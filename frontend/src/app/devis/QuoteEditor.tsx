'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useMemo, useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { SELLER_FIELD_LABELS } from '@/lib/offers';
import {
  centsToInput,
  draftFromQuote,
  formatCents,
  formatRate,
  LINE_TYPE_LABELS,
  lineTotalHt,
  parseEuros,
  parseQuantity,
  previewTotals,
  quantityToInput,
  type Quote,
  type QuoteDraftInput,
  type QuoteLineInput,
  type QuoteLineType,
  type QuoteUnit,
  UNIT_LABELS,
  type VatRateOption,
} from '@/lib/quotes';

interface LineRow {
  key: string;
  line_type: QuoteLineType;
  designation: string;
  details: string;
  quantity: string;
  unit: QuoteUnit;
  price: string;
  vat_rate_code: string;
}

interface Form {
  title: string;
  scope: string;
  client_name: string;
  client_company: string;
  client_address: string;
  execution_place: string;
  validity_days: string;
  start_date: string;
  duration: string;
  payment_terms: string;
  deposit: string;
  deposit_kind: 'acompte' | 'arrhes';
  quote_is_free: boolean;
  quote_fee: string;
  notes: string;
  lines: LineRow[];
}

const newLine = (vat: string): LineRow => ({ key: crypto.randomUUID(), line_type: 'service', designation: '', details: '', quantity: '1', unit: 'lump_sum', price: '', vat_rate_code: vat });

function formFromDraft(draft: QuoteDraftInput): Form {
  return {
    title: draft.title,
    scope: draft.scope,
    client_name: draft.client.name,
    client_company: draft.client.company_name ?? '',
    client_address: draft.client.address ?? '',
    execution_place: draft.client.execution_place,
    validity_days: String(draft.validity_days),
    start_date: draft.start_date ?? '',
    duration: draft.duration ?? '',
    payment_terms: draft.payment_terms ?? '',
    deposit: centsToInput(draft.deposit_cents),
    deposit_kind: draft.deposit_kind ?? 'acompte',
    quote_is_free: draft.quote_is_free,
    quote_fee: centsToInput(draft.quote_fee_cents),
    notes: draft.notes ?? '',
    lines: draft.lines.map((line) => ({
      key: crypto.randomUUID(),
      line_type: line.line_type,
      designation: line.designation,
      details: line.details ?? '',
      quantity: quantityToInput(line.quantity_milli),
      unit: line.unit,
      price: centsToInput(line.unit_price_cents),
      vat_rate_code: line.vat_rate_code,
    })),
  };
}

/**
 * Rédaction d'un devis par le prestataire, dans une demande de mise en relation acceptée
 * (décision 36) : création (`POST /me/quotes`) ou modification d'un brouillon (`PUT`), puis
 * envoi (`POST …/send`). Les taux de TVA viennent de l'API (règles vérifiées) ; les totaux
 * affichés sont un aperçu, ceux du serveur font foi.
 */
export function QuoteEditor({ matchRequest, quote, clientName }: { matchRequest?: string; quote?: Quote; clientName?: string }) {
  const router = useRouter();
  const [rates, setRates] = useState<VatRateOption[] | null>(null);
  const [form, setForm] = useState<Form | null>(quote ? formFromDraft(draftFromQuote(quote)) : null);
  const [error, setError] = useState<ApiError | null>(null);
  const [missing, setMissing] = useState<string[] | null>(null);
  const [busy, setBusy] = useState<'save' | 'send' | null>(null);
  const [confirmSend, setConfirmSend] = useState(false);
  const [confirmDelete, setConfirmDelete] = useState(false);

  useEffect(() => {
    let active = true;
    api<{ data: VatRateOption[] }>('me/quotes/vat-rates')
      .then((response) => {
        if (!active) return;
        setRates(response.data);
        const fallback = response.data.find((rate) => !rate.exclusive)?.code ?? response.data[0]?.code ?? '';
        setForm(
          (current) =>
            current ?? {
              title: '',
              scope: '',
              client_name: clientName ?? '',
              client_company: '',
              client_address: '',
              execution_place: '',
              validity_days: '30',
              start_date: '',
              duration: '',
              payment_terms: '',
              deposit: '',
              deposit_kind: 'acompte',
              quote_is_free: true,
              quote_fee: '',
              notes: '',
              lines: [newLine(fallback)],
            },
        );
      })
      .catch((exception) => active && setError(exception instanceof ApiError ? exception : new ApiError(0, {})));
    return () => {
      active = false;
    };
  }, [clientName]);

  const parsedLines = useMemo<QuoteLineInput[]>(
    () =>
      (form?.lines ?? []).map((line) => ({
        line_type: line.line_type,
        designation: line.designation.trim(),
        details: line.details.trim() || null,
        quantity_milli: parseQuantity(line.quantity) ?? 0,
        unit: line.unit,
        unit_price_cents: parseEuros(line.price) ?? 0,
        vat_rate_code: line.vat_rate_code,
      })),
    [form?.lines],
  );
  const totals = useMemo(() => previewTotals(parsedLines, rates ?? []), [parsedLines, rates]);

  if (error && !form) return <p className="alert alert--error">{error.message}</p>;
  if (!form || !rates) return <div className="skeleton" style={{ height: 480 }} />;

  if (rates.length === 0) {
    return (
      <div className="card empty">
        <p>
          Les devis ne sont pas encore disponibles : les taux de TVA doivent d&apos;abord être vérifiés par un expert-comptable. Vous pouvez continuer à
          échanger dans la messagerie.
        </p>
        <Link href="/messages" className="btn btn--outline">
          Retour aux messages
        </Link>
      </div>
    );
  }

  const set = <K extends keyof Form>(key: K, value: Form[K]) => setForm({ ...form, [key]: value });
  const setLine = (key: string, patch: Partial<LineRow>) => set('lines', form.lines.map((line) => (line.key === key ? { ...line, ...patch } : line)));
  const fieldError = (name: string) => error?.field(name);

  function payload(): QuoteDraftInput | null {
    if (!form) return null;
    const invalid: Record<string, string[]> = {};
    form.lines.forEach((line, index) => {
      if (parseQuantity(line.quantity) === null || parseQuantity(line.quantity) === 0) invalid[`lines.${index}.quantity_milli`] = ['Quantité invalide, par exemple 1 ou 2,5.'];
      if (parseEuros(line.price) === null) invalid[`lines.${index}.unit_price_cents`] = ['Prix invalide, par exemple 450 ou 450,50.'];
    });
    const deposit = form.deposit.trim() ? parseEuros(form.deposit) : null;
    if (form.deposit.trim() && deposit === null) invalid.deposit_cents = ['Montant invalide.'];
    const fee = form.quote_is_free ? null : parseEuros(form.quote_fee);
    if (!form.quote_is_free && fee === null) invalid.quote_fee_cents = ['Indiquez le prix du devis.'];
    if (Object.keys(invalid).length > 0) {
      setError(new ApiError(422, { message: 'Certaines valeurs sont invalides.', errors: invalid }));
      return null;
    }
    return {
      ...(matchRequest ? { match_request: matchRequest } : {}),
      title: form.title.trim(),
      scope: form.scope.trim(),
      client: {
        name: form.client_name.trim(),
        company_name: form.client_company.trim() || null,
        registration_number: null,
        address: form.client_address.trim() || null,
        execution_place: form.execution_place.trim(),
      },
      validity_days: Number(form.validity_days) || 30,
      start_date: form.start_date || null,
      duration: form.duration.trim() || null,
      payment_terms: form.payment_terms.trim() || null,
      deposit_cents: deposit,
      deposit_kind: deposit ? form.deposit_kind : null,
      quote_is_free: form.quote_is_free,
      quote_fee_cents: fee,
      keep_replaced_parts: null,
      notes: form.notes.trim() || null,
      lines: parsedLines,
    };
  }

  async function save(andSend: boolean) {
    const body = payload();
    if (!body) return;
    setBusy(andSend ? 'send' : 'save');
    setError(null);
    setMissing(null);
    try {
      const saved = quote
        ? await api<{ data: Quote }>(`me/quotes/${quote.uuid}`, { method: 'PUT', body })
        : await api<{ data: Quote }>('me/quotes', { method: 'POST', body });
      if (andSend) await api(`me/quotes/${saved.data.uuid}/send`, { method: 'POST' });
      router.push(`/devis/${saved.data.uuid}${andSend ? '?envoye=1' : ''}`);
      router.refresh();
    } catch (exception) {
      if (exception instanceof ApiError && exception.code === 'seller_profile_incomplete') {
        setMissing(Array.isArray(exception.body.missing) ? (exception.body.missing as string[]) : []);
      } else {
        setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      }
      setBusy(null);
      setConfirmSend(false);
    }
  }

  return (
    <form
      className="quote-editor"
      onSubmit={(event) => {
        event.preventDefault();
        void save(false);
      }}
    >
      {missing ? (
        <div className="alert alert--error">
          <p style={{ margin: 0 }}>Complétez d&apos;abord votre identité professionnelle, qui figure sur chaque devis :</p>
          <ul style={{ margin: '8px 0' }}>
            {missing.map((field) => (
              <li key={field}>{SELLER_FIELD_LABELS[field] ?? field}</li>
            ))}
          </ul>
          <Link href="/espace/identite" className="link-arrow">
            Compléter mon identité <Icon name="arrowRight" size={16} />
          </Link>
        </div>
      ) : null}

      <section className="card form-stack">
        <h2 className="quote-editor__title">Le devis</h2>
        <TextField label="Titre" value={form.title} onChange={(event) => set('title', event.target.value)} maxLength={160} counter error={fieldError('title')} hint="Par exemple : Rédaction des statuts de votre SAS." />
        <TextAreaField
          label="Objet de la prestation"
          value={form.scope}
          onChange={(event) => set('scope', event.target.value)}
          maxLength={5000}
          rows={4}
          counter
          error={fieldError('scope')}
          hint="Ce que vous allez faire, en quelques phrases (20 caractères au moins)."
        />
      </section>

      <section className="card form-stack">
        <h2 className="quote-editor__title">Le client</h2>
        <div className="quote-editor__grid">
          <TextField label="Nom du client" value={form.client_name} onChange={(event) => set('client_name', event.target.value)} maxLength={160} error={fieldError('client.name')} />
          <TextField label="Société (facultatif)" value={form.client_company} onChange={(event) => set('client_company', event.target.value)} maxLength={160} error={fieldError('client.company_name')} />
        </div>
        <TextField label="Adresse du client (facultatif)" value={form.client_address} onChange={(event) => set('client_address', event.target.value)} maxLength={300} error={fieldError('client.address')} />
        <TextField
          label="Lieu d'exécution"
          value={form.execution_place}
          onChange={(event) => set('execution_place', event.target.value)}
          maxLength={300}
          error={fieldError('client.execution_place')}
          hint="Une adresse, ou « À distance »."
        />
      </section>

      <section className="card form-stack">
        <h2 className="quote-editor__title">Les lignes</h2>
        <div className="quote-lines">
          {form.lines.map((line, index) => (
            <div key={line.key} className="quote-line">
              <div className="quote-line__head">
                <span className="quote-line__number">Ligne {index + 1}</span>
                {form.lines.length > 1 ? (
                  <button type="button" className="report-link" onClick={() => set('lines', form.lines.filter((item) => item.key !== line.key))}>
                    <Icon name="close" size={14} /> Retirer
                  </button>
                ) : null}
              </div>
              <div className="quote-line__main">
                <TextField label="Désignation" value={line.designation} onChange={(event) => setLine(line.key, { designation: event.target.value })} maxLength={300} error={fieldError(`lines.${index}.designation`)} />
                <div className="field">
                  <label className="field__label" htmlFor={`type-${line.key}`}>
                    Type
                  </label>
                  <select id={`type-${line.key}`} className="select" value={line.line_type} onChange={(event) => setLine(line.key, { line_type: event.target.value as QuoteLineType })}>
                    {Object.entries(LINE_TYPE_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                </div>
              </div>
              <div className="quote-line__numbers">
                <TextField label="Quantité" inputMode="decimal" value={line.quantity} onChange={(event) => setLine(line.key, { quantity: event.target.value })} error={fieldError(`lines.${index}.quantity_milli`)} />
                <div className="field">
                  <label className="field__label" htmlFor={`unit-${line.key}`}>
                    Unité
                  </label>
                  <select id={`unit-${line.key}`} className="select" value={line.unit} onChange={(event) => setLine(line.key, { unit: event.target.value as QuoteUnit })}>
                    {Object.entries(UNIT_LABELS).map(([value, label]) => (
                      <option key={value} value={value}>
                        {label}
                      </option>
                    ))}
                  </select>
                </div>
                <TextField label="Prix unitaire HT (€)" inputMode="decimal" value={line.price} onChange={(event) => setLine(line.key, { price: event.target.value })} error={fieldError(`lines.${index}.unit_price_cents`)} />
                <div className="field">
                  <label className="field__label" htmlFor={`vat-${line.key}`}>
                    TVA
                  </label>
                  <select id={`vat-${line.key}`} className="select" value={line.vat_rate_code} onChange={(event) => setLine(line.key, { vat_rate_code: event.target.value })}>
                    {rates.map((rate) => (
                      <option key={rate.code} value={rate.code}>
                        {rate.label} ({formatRate(rate.basis_points)})
                      </option>
                    ))}
                  </select>
                  {fieldError(`lines.${index}.vat_rate_code`) ? <span className="field__error">{fieldError(`lines.${index}.vat_rate_code`)}</span> : null}
                </div>
                <div className="quote-line__total">
                  <span>Total HT</span>
                  <strong>{formatCents(lineTotalHt(parsedLines[index]))}</strong>
                </div>
              </div>
              <TextField label="Détail (facultatif)" value={line.details} onChange={(event) => setLine(line.key, { details: event.target.value })} maxLength={1000} error={fieldError(`lines.${index}.details`)} />
            </div>
          ))}
        </div>
        {fieldError('lines') ? <p className="field__error">{fieldError('lines')}</p> : null}
        <button type="button" className="btn btn--outline" style={{ alignSelf: 'flex-start' }} onClick={() => set('lines', [...form.lines, newLine(form.lines[form.lines.length - 1]?.vat_rate_code ?? rates[0].code)])} disabled={form.lines.length >= 100}>
          <Icon name="plus" size={16} /> Ajouter une ligne
        </button>

        <dl className="quote-totals">
          <div>
            <dt>Total HT</dt>
            <dd>{formatCents(totals.ht_cents)}</dd>
          </div>
          {totals.vat_breakdown.map((entry) => (
            <div key={entry.code}>
              <dt>TVA {formatRate(entry.basis_points)}</dt>
              <dd>{formatCents(entry.vat_cents)}</dd>
            </div>
          ))}
          <div className="quote-totals__ttc">
            <dt>Total TTC</dt>
            <dd>{formatCents(totals.ttc_cents)}</dd>
          </div>
        </dl>
        <p className="field__hint" style={{ margin: 0 }}>
          Aperçu calculé pendant la saisie ; les montants définitifs sont recalculés à l&apos;enregistrement.
        </p>
      </section>

      <section className="card form-stack">
        <h2 className="quote-editor__title">Les conditions</h2>
        <div className="quote-editor__grid">
          <TextField label="Validité (jours)" type="number" min={1} max={180} value={form.validity_days} onChange={(event) => set('validity_days', event.target.value)} error={fieldError('validity_days')} />
          <TextField label="Date de début (facultatif)" type="date" value={form.start_date} onChange={(event) => set('start_date', event.target.value)} error={fieldError('start_date')} />
        </div>
        <TextField label="Durée estimée (facultatif)" value={form.duration} onChange={(event) => set('duration', event.target.value)} maxLength={200} error={fieldError('duration')} hint="Par exemple : trois semaines." />
        <TextAreaField label="Conditions de paiement (facultatif)" value={form.payment_terms} onChange={(event) => set('payment_terms', event.target.value)} maxLength={1000} rows={2} error={fieldError('payment_terms')} />
        <div className="quote-editor__grid">
          <TextField label="Acompte ou arrhes (€, facultatif)" inputMode="decimal" value={form.deposit} onChange={(event) => set('deposit', event.target.value)} error={fieldError('deposit_cents')} />
          <div className="field">
            <label className="field__label" htmlFor="deposit-kind">
              Nature de la somme versée
            </label>
            <select id="deposit-kind" className="select" value={form.deposit_kind} onChange={(event) => set('deposit_kind', event.target.value as Form['deposit_kind'])} disabled={!form.deposit.trim()}>
              <option value="acompte">Acompte</option>
              <option value="arrhes">Arrhes</option>
            </select>
          </div>
        </div>
        <div className="quote-editor__grid">
          <label className="checkbox">
            <input type="checkbox" checked={form.quote_is_free} onChange={(event) => set('quote_is_free', event.target.checked)} />
            <span>Devis gratuit</span>
          </label>
          {!form.quote_is_free ? (
            <TextField label="Prix du devis (€)" inputMode="decimal" value={form.quote_fee} onChange={(event) => set('quote_fee', event.target.value)} error={fieldError('quote_fee_cents')} />
          ) : null}
        </div>
        <TextAreaField label="Notes (facultatif)" value={form.notes} onChange={(event) => set('notes', event.target.value)} maxLength={3000} rows={3} error={fieldError('notes')} />
      </section>

      <FormError message={error && !Object.keys(error.errors).length ? error.message : error ? 'Vérifiez les champs signalés.' : null} />

      <div className="quote-editor__actions">
        {quote ? (
          <button type="button" className="btn btn--ghost" onClick={() => setConfirmDelete(true)}>
            Supprimer le brouillon
          </button>
        ) : (
          <span />
        )}
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <button type="submit" className="btn btn--outline" disabled={busy !== null}>
            {busy === 'save' ? 'Enregistrement…' : 'Enregistrer le brouillon'}
          </button>
          <button type="button" className="btn btn--blue" disabled={busy !== null} onClick={() => setConfirmSend(true)}>
            <Icon name="send" size={16} /> Envoyer au client
          </button>
        </div>
      </div>

      {confirmSend ? (
        <ConfirmDialog
          title="Envoyer ce devis ?"
          confirmLabel="Envoyer"
          onConfirm={() => save(true)}
          onClose={() => setConfirmSend(false)}
        >
          <p>Une fois envoyé, le devis ne se modifie plus : le client le reçoit tel quel. Pour le changer ensuite, vous ferez une nouvelle version.</p>
        </ConfirmDialog>
      ) : null}
      {confirmDelete && quote ? (
        <ConfirmDialog
          title="Supprimer ce brouillon ?"
          confirmLabel="Supprimer"
          onConfirm={async () => {
            await api(`me/quotes/${quote.uuid}`, { method: 'DELETE' });
            router.push('/devis');
          }}
          onClose={() => setConfirmDelete(false)}
        >
          <p>Le brouillon sera supprimé définitivement.</p>
        </ConfirmDialog>
      ) : null}
    </form>
  );
}
