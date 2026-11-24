'use client';

import { useEffect, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { ModerationInfo } from '@/lib/types';
import { Dialog } from './Dialog';
import { FormError, TextAreaField, TextField } from './Field';
import { Icon } from './Icon';

type TargetType = 'project' | 'provider_profile' | 'event' | 'article' | 'offer' | 'appointment_type';

/**
 * Signalement d'un contenu public (règlement européen sur les services numériques, art. 16) :
 * catégorie, explication, nom et email sans compte, déclaration de bonne foi. Sans compte, la
 * réponse donne une seule fois un jeton de suivi, affiché ici.
 */
export function ReportButton({ type, reference, label = 'Signaler ce contenu' }: { type: TargetType; reference: string; label?: string }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <button type="button" className="report-link" onClick={() => setOpen(true)}>
        <Icon name="flag" size={14} /> {label}
      </button>
      {open ? <PublicReportDialog type={type} reference={reference} onClose={() => setOpen(false)} /> : null}
    </>
  );
}

function PublicReportDialog({ type, reference, onClose }: { type: TargetType; reference: string; onClose: () => void }) {
  const { user } = useSession();
  const [info, setInfo] = useState<ModerationInfo | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [category, setCategory] = useState('');
  const [explanation, setExplanation] = useState('');
  const [locationDetail, setLocationDetail] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [goodFaith, setGoodFaith] = useState(false);
  const [homepage, setHomepage] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  const [result, setResult] = useState<{ token: string | null; uuid: string } | null>(null);

  useEffect(() => {
    let active = true;
    api<{ data: ModerationInfo }>('moderation/info')
      .then((response) => active && setInfo(response.data))
      .catch(() => active && setLoadError(true));
    return () => {
      active = false;
    };
  }, []);

  const selected = info?.categories.find((item) => item.key === category);
  const identityRequired = !user && !(selected?.identity_optional ?? false);
  const minimum = info?.explanation.min ?? 20;

  async function submit() {
    setSaving(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {
        target_type: type,
        target_ref: reference,
        category,
        explanation: explanation.trim(),
        location_detail: locationDetail.trim() || null,
        good_faith: goodFaith,
      };
      if (!user) {
        body.name = name.trim() || null;
        body.email = email.trim() || null;
      }
      // Champ piège : rempli seulement par les robots, refusé par l'API.
      if (homepage) body.homepage = homepage;
      const response = await api<{ data: { uuid: string }; tracking_token?: string }>('moderation/notices', { method: 'POST', body });
      setResult({ token: response.tracking_token ?? null, uuid: response.data.uuid });
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog title="Signaler un contenu" onClose={onClose} wide>
      {result ? (
        <div className="form-stack">
          <p className="alert alert--success">
            Votre signalement a été enregistré. Il sera examiné par une personne de l&apos;équipe, qui vous informera de sa décision{user ? ' dans votre espace et par email' : email ? ' par email' : ''}.
          </p>
          {result.token ? (
            <div className="note">
              <p style={{ margin: '0 0 8px' }}>
                <strong>Code de suivi</strong> : conservez-le, il ne sera plus affiché. Il vous permet de consulter la suite donnée et de contester la décision.
              </p>
              <code className="token">{result.token}</code>
              <p className="field__hint" style={{ marginTop: 8 }}>
                Référence du signalement : {result.uuid}
              </p>
            </div>
          ) : null}
          <button type="button" className="btn btn--dark btn--block" onClick={onClose}>
            Fermer
          </button>
        </div>
      ) : loadError ? (
        <p className="alert alert--error">Le formulaire de signalement ne peut pas être chargé pour le moment. Réessayez plus tard.</p>
      ) : !info ? (
        <div className="skeleton" style={{ height: 240 }} />
      ) : (
        <form
          className="form-stack"
          onSubmit={(event) => {
            event.preventDefault();
            void submit();
          }}
        >
          <div className="field">
            <label className="field__label" htmlFor="report-category">
              Quel est le problème ?
            </label>
            <select id="report-category" className="select" value={category} onChange={(event) => setCategory(event.target.value)} required>
              <option value="">Choisissez une catégorie</option>
              {info.categories.map((item) => (
                <option key={item.key} value={item.key}>
                  {item.label}
                </option>
              ))}
            </select>
            {error?.field('category') ? <span className="field__error">{error.field('category')}</span> : null}
          </div>

          {selected?.priority === 'urgent' ? (
            <p className="alert alert--info">
              Ce signalement est traité en priorité. En cas de danger immédiat, contactez aussi le 17 (police) ou le 112. Pour un contenu illégal, vous pouvez le
              signaler sur internet-signalement.gouv.fr (Pharos).
            </p>
          ) : null}

          <TextAreaField
            label="Expliquez pourquoi ce contenu pose problème"
            value={explanation}
            rows={5}
            maxLength={info.explanation.max}
            counter
            onChange={(event) => setExplanation(event.target.value)}
            error={error?.field('explanation')}
            hint={`${minimum} caractères au moins. Soyez précis : ce qui est en cause et, si vous le savez, pourquoi c'est illégal ou contraire aux règles.`}
          />
          <TextField
            label="Où se trouve le problème ? (facultatif)"
            value={locationDetail}
            maxLength={500}
            onChange={(event) => setLocationDetail(event.target.value)}
            error={error?.field('location_detail')}
            hint="Par exemple : le deuxième paragraphe, le titre, l'image."
          />

          {!user ? (
            <>
              <TextField
                label={identityRequired ? 'Votre nom' : 'Votre nom (facultatif)'}
                value={name}
                maxLength={120}
                autoComplete="name"
                onChange={(event) => setName(event.target.value)}
                error={error?.field('name')}
              />
              <TextField
                label={identityRequired ? 'Votre email' : 'Votre email (facultatif)'}
                type="email"
                value={email}
                autoComplete="email"
                onChange={(event) => setEmail(event.target.value)}
                error={error?.field('email')}
                hint="Pour l'accusé de réception et la décision. Il n'est jamais communiqué à l'auteur du contenu."
              />
            </>
          ) : (
            <p className="field__hint">Votre identité n&apos;est jamais communiquée à l&apos;auteur du contenu.</p>
          )}

          <div className="visually-hidden" aria-hidden="true">
            <label>
              Site web
              <input tabIndex={-1} autoComplete="off" value={homepage} onChange={(event) => setHomepage(event.target.value)} />
            </label>
          </div>

          <label className="checkbox">
            <input type="checkbox" checked={goodFaith} onChange={(event) => setGoodFaith(event.target.checked)} />
            <span>Je déclare de bonne foi que les informations de ce signalement sont exactes et complètes.</span>
          </label>
          {error?.field('good_faith') ? <span className="field__error">{error.field('good_faith')}</span> : null}

          <FormError
            message={
              error && !['category', 'explanation', 'location_detail', 'name', 'email', 'good_faith'].some((field) => error.field(field)) ? error.message : null
            }
          />
          <button type="submit" className="btn btn--dark btn--block" disabled={!category || !goodFaith || explanation.trim().length < minimum || saving}>
            {saving ? 'Envoi…' : 'Envoyer le signalement'}
          </button>
          <p className="disclaimer">
            Chaque signalement est examiné par une personne, sans décision automatique. Vous serez informé de la décision et pourrez la contester pendant{' '}
            {info.appeal_window_months} mois.
          </p>
        </form>
      )}
    </Dialog>
  );
}
