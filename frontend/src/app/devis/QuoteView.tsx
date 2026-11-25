'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Dialog } from '@/components/Dialog';
import { FormError, TextAreaField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { api, apiDownload, ApiError, saveBlob } from '@/lib/api';
import { formatDate } from '@/lib/format';
import {
  DECLINE_LABELS,
  type DeclineReason,
  formatCents,
  formatQuantity,
  formatRate,
  LINE_TYPE_LABELS,
  type Quote,
  STATUS_CLASS,
  STATUS_LABELS,
  translated,
  unitLabel,
  withConversation,
} from '@/lib/quotes';

/**
 * Devis vu par l'une des deux parties (`viewer_role`). Prestataire : télécharger, retirer,
 * faire une nouvelle version. Client : accepter (empreinte `content_hash` lue, attestations,
 * exécution immédiate pour un consommateur), refuser avec un motif, se rétracter dans le délai.
 */
export function QuoteView({ quote, onChanged, conversation = null }: { quote: Quote; onChanged: (quote: Quote) => void; conversation?: string | null }) {
  const router = useRouter();
  const [dialog, setDialog] = useState<'accept' | 'decline' | 'withdraw' | 'withdrawal' | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [now] = useState(() => Date.now());
  const isProvider = quote.viewer_role === 'provider';
  const hidden = quote.lines === null;
  const consumer = quote.mentions.consumer;
  const withdrawalOpen =
    !isProvider &&
    quote.status === 'accepted' &&
    consumer?.withdrawal_ends_at &&
    !quote.acceptance?.withdrawal_exercised_at &&
    new Date(consumer.withdrawal_ends_at).getTime() > now;

  async function download() {
    setDownloading(true);
    setError(null);
    try {
      saveBlob(await apiDownload(`me/quotes/${quote.uuid}/pdf`), `devis-${quote.number ?? quote.uuid}.pdf`);
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.message : 'Téléchargement impossible.');
    } finally {
      setDownloading(false);
    }
  }

  async function revise() {
    setError(null);
    try {
      const response = await api<{ data: Quote }>(`me/quotes/${quote.uuid}/revise`, { method: 'POST' });
      router.push(withConversation(`/devis/${response.data.uuid}`, conversation));
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.message : 'Action impossible.');
    }
  }

  return (
    <div className="quote-view">
      <div className="quote-view__bar">
        <Link href={conversation ? `/messages?c=${conversation}` : '/devis'} className="link-arrow">
          <Icon name="arrowLeft" size={16} /> {conversation ? 'Retour à la conversation' : 'Mes devis'}
        </Link>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          {quote.status !== 'draft' && !hidden ? (
            <button type="button" className="btn btn--outline btn--sm" onClick={() => void download()} disabled={downloading}>
              <Icon name="file" size={16} /> {downloading ? 'Préparation…' : 'Télécharger le PDF'}
            </button>
          ) : null}
          {isProvider && quote.status === 'sent' ? (
            <button type="button" className="btn btn--ghost btn--sm" onClick={() => setDialog('withdraw')}>
              Retirer le devis
            </button>
          ) : null}
          {isProvider && ['sent', 'declined', 'expired'].includes(quote.status) ? (
            <button type="button" className="btn btn--dark btn--sm" onClick={() => void revise()}>
              Nouvelle version
            </button>
          ) : null}
        </div>
      </div>
      <FormError message={error} />

      <article className="card quote-sheet">
        <header className="quote-sheet__head">
          <div>
            <p className="quote-sheet__eyebrow">
              Devis {quote.number ?? '(brouillon)'}
              {quote.version > 1 ? ` · version ${quote.version}` : ''}
            </p>
            <h1 className="quote-sheet__title">{quote.title}</h1>
            {!isProvider && quote.provider && !quote.provider.deleted ? (
              <p className="quote-sheet__from">
                De{' '}
                {quote.provider.slug ? <Link href={`/prestataires/${quote.provider.slug}`}>{quote.provider.display_name}</Link> : quote.provider.display_name}
              </p>
            ) : null}
          </div>
          <span className={`status ${STATUS_CLASS[quote.status]}`}>{STATUS_LABELS[quote.status]}</span>
        </header>

        {hidden ? (
          <p className="alert alert--error">Ce devis a été masqué par la modération : son contenu n&apos;est plus affiché.</p>
        ) : (
          <>
            <dl className="quote-sheet__meta">
              {quote.client ? (
                <div>
                  <dt>Client</dt>
                  <dd>
                    {quote.client.name}
                    {quote.client.company_name ? ` · ${quote.client.company_name}` : ''}
                  </dd>
                </div>
              ) : null}
              {quote.client ? (
                <div>
                  <dt>Lieu d&apos;exécution</dt>
                  <dd>{quote.client.execution_place}</dd>
                </div>
              ) : null}
              {quote.sent_at ? (
                <div>
                  <dt>Envoyé le</dt>
                  <dd>{formatDate(quote.sent_at)}</dd>
                </div>
              ) : null}
              <div>
                <dt>Validité</dt>
                <dd>{quote.validity.valid_until ? `Jusqu'au ${formatDate(quote.validity.valid_until)}` : `${quote.validity.days} jours après l'envoi`}</dd>
              </div>
              {quote.start_date ? (
                <div>
                  <dt>Début</dt>
                  <dd>{formatDate(quote.start_date)}</dd>
                </div>
              ) : null}
              {quote.duration ? (
                <div>
                  <dt>Durée</dt>
                  <dd>{quote.duration}</dd>
                </div>
              ) : null}
            </dl>

            {quote.scope ? <p className="quote-sheet__scope">{quote.scope}</p> : null}

            <div style={{ overflowX: 'auto' }}>
              <table className="table quote-table">
                <thead>
                  <tr>
                    <th scope="col">Désignation</th>
                    <th scope="col">Quantité</th>
                    <th scope="col">Prix unitaire HT</th>
                    <th scope="col">TVA</th>
                    <th scope="col">Total HT</th>
                  </tr>
                </thead>
                <tbody>
                  {(quote.lines ?? []).map((line) => (
                    <tr key={line.position}>
                      <td>
                        <strong>{line.designation}</strong>
                        <small className="quote-table__type">{LINE_TYPE_LABELS[line.line_type]}</small>
                        {line.details ? <small className="quote-table__details">{line.details}</small> : null}
                      </td>
                      <td>
                        {formatQuantity(line.quantity_milli)} {unitLabel(line.unit, line.quantity_milli)}
                      </td>
                      <td>{formatCents(line.unit_price_cents, quote.currency)}</td>
                      <td>{formatRate(line.vat.basis_points)}</td>
                      <td>{formatCents(line.total_ht_cents, quote.currency)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {quote.totals ? (
              <dl className="quote-totals">
                <div>
                  <dt>Total HT</dt>
                  <dd>{formatCents(quote.totals.ht_cents, quote.currency)}</dd>
                </div>
                {quote.totals.vat_breakdown.map((entry) => (
                  <div key={entry.code}>
                    <dt>TVA {formatRate(entry.basis_points)}</dt>
                    <dd>{formatCents(entry.vat_cents, quote.currency)}</dd>
                  </div>
                ))}
                <div className="quote-totals__ttc">
                  <dt>Total TTC</dt>
                  <dd>{formatCents(quote.totals.ttc_cents, quote.currency)}</dd>
                </div>
              </dl>
            ) : null}

            <dl className="quote-sheet__meta">
              {quote.payment_terms ? (
                <div>
                  <dt>Paiement</dt>
                  <dd>{quote.payment_terms}</dd>
                </div>
              ) : null}
              {quote.deposit ? (
                <div>
                  <dt>{quote.deposit.kind === 'arrhes' ? 'Arrhes' : 'Acompte'}</dt>
                  <dd>{formatCents(quote.deposit.cents, quote.currency)}</dd>
                </div>
              ) : null}
              <div>
                <dt>Devis</dt>
                <dd>{quote.quote_fee.is_free ? 'Gratuit' : `Payant : ${formatCents(quote.quote_fee.cents, quote.currency)}`}</dd>
              </div>
              {quote.notes ? (
                <div>
                  <dt>Notes</dt>
                  <dd>{quote.notes}</dd>
                </div>
              ) : null}
            </dl>

            <div className="quote-sheet__mentions">
              {quote.mentions.vat.map((mention) => (
                <p key={mention.code}>{translated(mention.text)}</p>
              ))}
              {consumer ? (
                <p>
                  Vous disposez d&apos;un délai de rétractation de {consumer.withdrawal_days} jours à compter de l&apos;acceptation du devis
                  {consumer.withdrawal_ends_at ? `, jusqu'au ${formatDate(consumer.withdrawal_ends_at)}` : ''}.
                </p>
              ) : null}
              <p>{quote.mentions.not_a_party}</p>
            </div>
          </>
        )}

        {quote.decline ? (
          <p className="alert alert--error">
            Refusé : {quote.decline.reason ? DECLINE_LABELS[quote.decline.reason] : 'sans motif'}
            {quote.decline.comment ? ` · ${quote.decline.comment}` : ''}
          </p>
        ) : null}
        {quote.acceptance ? (
          <p className="alert alert--success">
            Accepté le {formatDate(quote.acceptance.accepted_at)}
            {quote.acceptance.withdrawal_exercised_at ? ` · rétractation le ${formatDate(quote.acceptance.withdrawal_exercised_at)}` : ''}.
          </p>
        ) : null}
      </article>

      {!isProvider && quote.status === 'sent' && !hidden ? (
        <div className="quote-view__answer card">
          <p style={{ margin: 0 }}>Ce devis vous convient ?</p>
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            <button type="button" className="btn btn--outline" onClick={() => setDialog('decline')}>
              Refuser
            </button>
            <button type="button" className="btn btn--lime" onClick={() => setDialog('accept')}>
              <Icon name="check" size={16} /> Accepter le devis
            </button>
          </div>
        </div>
      ) : null}
      {withdrawalOpen ? (
        <button type="button" className="report-link" style={{ alignSelf: 'flex-start' }} onClick={() => setDialog('withdrawal')}>
          Exercer mon droit de rétractation
        </button>
      ) : null}

      {dialog === 'accept' ? <AcceptDialog quote={quote} onClose={() => setDialog(null)} onDone={onChanged} /> : null}
      {dialog === 'decline' ? <DeclineDialog quote={quote} onClose={() => setDialog(null)} onDone={onChanged} /> : null}
      {dialog === 'withdraw' ? (
        <ConfirmDialog
          title="Retirer ce devis ?"
          confirmLabel="Retirer"
          onConfirm={async () => onChanged((await api<{ data: Quote }>(`me/quotes/${quote.uuid}/withdraw`, { method: 'POST' })).data)}
          onClose={() => setDialog(null)}
        >
          <p>Le client ne pourra plus l&apos;accepter. Vous pourrez lui envoyer un nouveau devis ensuite.</p>
        </ConfirmDialog>
      ) : null}
      {dialog === 'withdrawal' ? (
        <ConfirmDialog
          title="Vous rétracter ?"
          confirmLabel="Me rétracter"
          onConfirm={async () => onChanged((await api<{ data: Quote }>(`me/quotes/${quote.uuid}/withdrawal`, { method: 'POST', body: { confirm: true } })).data)}
          onClose={() => setDialog(null)}
        >
          <p>Le prestataire sera prévenu que vous renoncez à ce devis. Cette décision est définitive.</p>
        </ConfirmDialog>
      ) : null}
    </div>
  );
}

function AcceptDialog({ quote, onClose, onDone }: { quote: Quote; onClose: () => void; onDone: (quote: Quote) => void }) {
  const [accept, setAccept] = useState(false);
  const [certified, setCertified] = useState<string[]>([]);
  const [express, setExpress] = useState(false);
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);
  const consumer = quote.mentions.consumer !== null;

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      const body: Record<string, unknown> = { content_hash: quote.content_hash, accept, certifications: certified };
      if (consumer) body.express_execution = express;
      onDone((await api<{ data: Quote }>(`me/quotes/${quote.uuid}/accept`, { method: 'POST', body })).data);
      onClose();
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      setBusy(false);
    }
  }

  return (
    <Dialog title="Accepter le devis" onClose={onClose}>
      <form
        className="form-stack"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <p style={{ margin: 0, fontFamily: 'var(--font-text)' }}>
          Montant : <strong>{formatCents(quote.totals?.ttc_cents, quote.currency)} TTC</strong>. Vous acceptez le devis tel qu&apos;il vous a été envoyé.
        </p>
        {quote.mentions.certifications.map((certification) => (
          <label key={certification.code} className="checkbox">
            <input
              type="checkbox"
              checked={certified.includes(certification.code)}
              onChange={(event) => setCertified(event.target.checked ? [...certified, certification.code] : certified.filter((code) => code !== certification.code))}
            />
            <span>{translated(certification.text)}</span>
          </label>
        ))}
        {consumer ? (
          <label className="checkbox">
            <input type="checkbox" checked={express} onChange={(event) => setExpress(event.target.checked)} />
            <span>Je demande que la prestation commence avant la fin du délai de rétractation (facultatif).</span>
          </label>
        ) : null}
        <label className="checkbox">
          <input type="checkbox" checked={accept} onChange={(event) => setAccept(event.target.checked)} required />
          <span>J&apos;accepte ce devis et m&apos;engage auprès du prestataire.</span>
        </label>
        {error?.field('accept') || error?.field('certifications') ? <span className="field__error">{error.field('accept') ?? error.field('certifications')}</span> : null}
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Annuler
          </button>
          <button type="submit" className="btn btn--lime" disabled={!accept || busy}>
            {busy ? 'Un instant…' : 'Accepter'}
          </button>
        </div>
      </form>
    </Dialog>
  );
}

function DeclineDialog({ quote, onClose, onDone }: { quote: Quote; onClose: () => void; onDone: (quote: Quote) => void }) {
  const [reason, setReason] = useState<DeclineReason | ''>('');
  const [comment, setComment] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);
    try {
      onDone((await api<{ data: Quote }>(`me/quotes/${quote.uuid}/decline`, { method: 'POST', body: { reason, comment: comment.trim() || null } })).data);
      onClose();
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      setBusy(false);
    }
  }

  return (
    <Dialog title="Refuser le devis" onClose={onClose}>
      <form
        className="form-stack"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <fieldset className="radio-list">
          <legend className="field__label">Pour quelle raison ?</legend>
          {(Object.keys(DECLINE_LABELS) as DeclineReason[]).map((key) => (
            <label key={key} className="checkbox">
              <input type="radio" name="decline-reason" checked={reason === key} onChange={() => setReason(key)} />
              <span>{DECLINE_LABELS[key]}</span>
            </label>
          ))}
          {error?.field('reason') ? <span className="field__error">{error.field('reason')}</span> : null}
        </fieldset>
        <TextAreaField label="Un mot pour le prestataire (facultatif)" value={comment} onChange={(event) => setComment(event.target.value)} maxLength={1000} rows={3} error={error?.field('comment')} />
        <FormError message={error && !Object.keys(error.errors).length ? error.message : null} />
        <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end' }}>
          <button type="button" className="btn btn--outline" onClick={onClose}>
            Annuler
          </button>
          <button type="submit" className="btn btn--dark" disabled={!reason || busy}>
            {busy ? 'Un instant…' : 'Refuser le devis'}
          </button>
        </div>
      </form>
    </Dialog>
  );
}
