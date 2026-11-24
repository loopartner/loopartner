'use client';

import { useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { PRIVATE_REPORT_REASONS } from '@/lib/counterpart';
import { Dialog } from './Dialog';
import { FormError, TextAreaField } from './Field';

/**
 * Signalement d'un message ou d'une demande de mise en relation (contenus privés) : motif dans la
 * liste fermée de l'API et commentaire facultatif. L'auteur n'apprend jamais qui l'a signalé.
 */
export function PrivateReportDialog({ path, subject, onClose }: { path: string; subject: string; onClose: () => void }) {
  const [reason, setReason] = useState<string>('');
  const [comment, setComment] = useState('');
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState(false);

  async function submit() {
    setSaving(true);
    setError(null);
    try {
      await api(path, { method: 'POST', body: { reason, comment: comment.trim() || null } });
      setDone(true);
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Dialog title={`Signaler ${subject}`} onClose={onClose}>
      {done ? (
        <>
          <p className="alert alert--success">
            Merci, votre signalement a bien été transmis à l&apos;équipe de modération. La personne concernée ne saura pas qui l&apos;a signalée.
          </p>
          <button type="button" className="btn btn--dark btn--block" onClick={onClose}>
            Fermer
          </button>
        </>
      ) : (
        <form
          className="form-stack"
          onSubmit={(event) => {
            event.preventDefault();
            void submit();
          }}
        >
          <fieldset className="radio-list">
            <legend className="field__label">Motif</legend>
            {PRIVATE_REPORT_REASONS.map((option) => (
              <label key={option.key} className="checkbox">
                <input type="radio" name="reason" value={option.key} checked={reason === option.key} onChange={() => setReason(option.key)} />
                <span>{option.label}</span>
              </label>
            ))}
            {error?.field('reason') ? <span className="field__error">{error.field('reason')}</span> : null}
          </fieldset>
          <TextAreaField
            label="Précisions (facultatif)"
            value={comment}
            maxLength={1000}
            counter
            rows={4}
            onChange={(event) => setComment(event.target.value)}
            error={error?.field('comment')}
            hint="N'indiquez pas de données sensibles (santé, coordonnées bancaires…)."
          />
          <FormError message={error && !error.field('reason') && !error.field('comment') ? error.message : null} />
          <button type="submit" className="btn btn--dark btn--block" disabled={!reason || saving}>
            {saving ? 'Envoi…' : 'Envoyer le signalement'}
          </button>
        </form>
      )}
    </Dialog>
  );
}
