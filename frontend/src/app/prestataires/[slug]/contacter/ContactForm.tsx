'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { FormError, TextAreaField } from '@/components/Field';
import { api, ApiError } from '@/lib/api';
import type { OwnProject, Paginated, ProviderProfile } from '@/lib/types';

const MIN = 20;
const MAX = 5000;

/**
 * Demande de contact (POST /providers/{slug}/contact) : message et, au choix, un de ses projets
 * publiés. Le prestataire ne voit le nom du demandeur qu'après avoir accepté.
 */
export function ContactForm({ provider }: { provider: ProviderProfile }) {
  const router = useRouter();
  const [message, setMessage] = useState('');
  const [project, setProject] = useState('');
  const [projects, setProjects] = useState<OwnProject[]>([]);
  const [error, setError] = useState<ApiError | null>(null);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let active = true;
    api<Paginated<OwnProject>>('me/projects', { query: { per_page: 50 } })
      .then((response) => active && setProjects(response.data.filter((item) => item.status === 'published')))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  async function submit() {
    setSaving(true);
    setError(null);
    try {
      const response = await api<{ data: { uuid: string } }>(`providers/${encodeURIComponent(provider.slug)}/contact`, {
        method: 'POST',
        body: { message: message.trim(), project: project || null },
      });
      router.push(`/messages?d=${response.data.uuid}`);
    } catch (exception) {
      setError(exception instanceof ApiError ? exception : new ApiError(0, {}));
      setSaving(false);
    }
  }

  return (
    <div className="with-aside with-aside--right">
      <form
        className="card form-stack"
        onSubmit={(event) => {
          event.preventDefault();
          void submit();
        }}
      >
        <TextAreaField
          label="Votre message"
          value={message}
          rows={8}
          maxLength={MAX}
          counter
          onChange={(event) => setMessage(event.target.value)}
          error={error?.field('message')}
          hint={`Présentez votre besoin, vos délais et vos questions (${MIN} caractères au moins). N'indiquez ni téléphone ni email : vous échangerez par la messagerie.`}
        />
        {projects.length > 0 ? (
          <div className="field">
            <label className="field__label" htmlFor="contact-project">
              Projet concerné (facultatif)
            </label>
            <select id="contact-project" className="select" value={project} onChange={(event) => setProject(event.target.value)}>
              <option value="">Aucun</option>
              {projects.map((item) => (
                <option key={item.uuid} value={item.uuid}>
                  {item.title}
                </option>
              ))}
            </select>
            {error?.field('project') ? <span className="field__error">{error.field('project')}</span> : null}
          </div>
        ) : null}
        <FormError message={error && !error.field('message') && !error.field('project') ? error.message : null} />
        <button type="submit" className="btn btn--lime btn--lg" disabled={saving || message.trim().length < MIN}>
          {saving ? 'Envoi…' : 'Envoyer ma demande'}
        </button>
      </form>
      <aside className="aside-stack">
        <div className="card aside-card">
          <h3>Comment ça se passe ?</h3>
          <p>
            {provider.display_name} reçoit votre demande par email et peut l&apos;accepter ou la refuser. Votre nom ne lui est montré qu&apos;après
            acceptation. Une conversation s&apos;ouvre alors dans vos messages.
          </p>
        </div>
      </aside>
    </div>
  );
}
