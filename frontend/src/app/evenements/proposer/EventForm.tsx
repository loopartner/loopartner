'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { FormError, TextAreaField, TextField } from '@/components/Field';
import { Icon } from '@/components/Icon';
import { MediaUpload } from '@/components/MediaUpload';
import { api, ApiError } from '@/lib/api';
import { useSession } from '@/lib/session';
import type { Category } from '@/lib/types';

const DURATIONS = [30, 45, 60, 90, 120] as const;

/** Heure locale de fin, au format attendu par l'API (`AAAA-MM-JJTHH:MM`, fuseau à part). */
function addMinutes(local: string, minutes: number): string {
  const [date, time] = local.split('T');
  const [year, month, day] = date.split('-').map(Number);
  const [hour, minute] = time.split(':').map(Number);
  const value = new Date(Date.UTC(year, month - 1, day, hour, minute + minutes));
  const pad = (number: number) => String(number).padStart(2, '0');
  return `${value.getUTCFullYear()}-${pad(value.getUTCMonth() + 1)}-${pad(value.getUTCDate())}T${pad(value.getUTCHours())}:${pad(value.getUTCMinutes())}`;
}

/**
 * Proposition d'un webinar (maquette « proposer un webinar ») : brouillon `POST /me/events`,
 * puis `POST /me/events/{uuid}/submit` (validation par un admin avant toute publication).
 * Dates saisies en heure de Paris ; le serveur les convertit en UTC.
 */
export function EventForm() {
  const router = useRouter();
  const { user, loading, hasRole } = useSession();
  const [categories, setCategories] = useState<Category[]>([]);
  const [uuid, setUuid] = useState<string | null>(null);
  const [title, setTitle] = useState('');
  const [category, setCategory] = useState('');
  const [description, setDescription] = useState('');
  const [date, setDate] = useState('');
  const [time, setTime] = useState('');
  const [minutes, setMinutes] = useState<number>(45);
  const [language, setLanguage] = useState('fr');
  const [onlineUrl, setOnlineUrl] = useState('');
  const [capacity, setCapacity] = useState('');
  const [organizerName, setOrganizerName] = useState('');
  const [cover, setCover] = useState<string | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    api<{ data: Category[] }>('categories')
      .then((response) => setCategories(response.data))
      .catch(() => setCategories([]));
  }, []);

  if (loading) return <div className="skeleton" style={{ height: 400 }} />;

  if (!user || !hasRole('prestataire', 'entrepreneur', 'redacteur', 'admin')) {
    return (
      <div className="card empty">
        <p>Les webinars sont proposés par les prestataires au profil publié, les entrepreneurs et l&apos;équipe.</p>
        {!user ? (
          <Link href="/connexion?next=/evenements/proposer" className="btn btn--lime">
            Se connecter
          </Link>
        ) : null}
      </div>
    );
  }

  const isProvider = hasRole('prestataire');

  function payload() {
    const starts = date && time ? `${date}T${time}` : '';
    return {
      title: title.trim(),
      description: description.trim(),
      type: 'webinar',
      format: 'online',
      language,
      categories: category ? [category] : [],
      starts_at: starts,
      ends_at: starts ? addMinutes(starts, minutes) : '',
      timezone: 'Europe/Paris',
      online_url: onlineUrl.trim() || null,
      is_free: true,
      capacity: capacity.trim() ? Number.parseInt(capacity, 10) : null,
      ...(isProvider ? {} : { organizer_name: organizerName.trim() || null }),
      ...(cover ? { cover } : {}),
    };
  }

  function showErrors(exception: unknown) {
    if (exception instanceof ApiError) {
      const fields = Object.fromEntries(Object.entries(exception.errors).map(([key, messages]) => [key.split('.')[0], messages[0]]));
      setErrors(fields);
      setError(Object.keys(fields).length > 0 ? 'Certains champs sont à corriger.' : exception.message);
    } else {
      setError("L'enregistrement a échoué.");
    }
  }

  async function save(): Promise<string> {
    setErrors({});
    setError(null);
    setNotice(null);
    const response = uuid
      ? await api<{ data: { uuid: string } }>(`me/events/${uuid}`, { method: 'PATCH', body: payload() })
      : await api<{ data: { uuid: string } }>('me/events', { method: 'POST', body: payload() });
    setUuid(response.data.uuid);
    return response.data.uuid;
  }

  return (
    <form
      className="card form-stack"
      style={{ maxWidth: 760, margin: '0 auto', padding: 48, gap: 28 }}
      onSubmit={async (event) => {
        event.preventDefault();
        setBusy(true);
        try {
          const id = await save();
          await api(`me/events/${id}/submit`, { method: 'POST' });
          router.push('/mes-evenements?envoye=1');
        } catch (exception) {
          showErrors(exception);
        } finally {
          setBusy(false);
        }
      }}
      noValidate
    >
      <TextField label="Titre du webinar" placeholder="Ex : Comment rédiger ses statuts sans erreur" value={title} onChange={(event) => setTitle(event.target.value)} maxLength={150} counter error={errors.title} />
      <div className="field">
        <label className="field__label" htmlFor="event-category">
          Catégorie
        </label>
        <select id="event-category" className="select" value={category} onChange={(event) => setCategory(event.target.value)}>
          <option value="">Sélectionnez une catégorie</option>
          {categories.map((item) => (
            <option key={item.slug} value={item.slug}>
              {item.name}
            </option>
          ))}
        </select>
        {errors.categories ? <span className="field__error">{errors.categories}</span> : null}
      </div>
      <TextAreaField
        label="Description"
        rows={6}
        placeholder="Présentez le contenu, les points abordés et à qui s'adresse ce webinar…"
        value={description}
        onChange={(event) => setDescription(event.target.value)}
        maxLength={10000}
        error={errors.description}
        hint="30 caractères au moins. Les liens écrits dans le texte ne deviennent pas cliquables."
      />
      {!isProvider ? (
        <TextField label="Nom de l'organisateur" placeholder="Votre nom ou celui de votre structure" value={organizerName} onChange={(event) => setOrganizerName(event.target.value)} maxLength={120} error={errors.organizer_name} />
      ) : null}
      <div className="card-grid card-grid--2" style={{ gap: 20 }}>
        <TextField label="Date" type="date" value={date} onChange={(event) => setDate(event.target.value)} error={errors.starts_at} />
        <TextField label="Heure (heure de Paris)" type="time" value={time} onChange={(event) => setTime(event.target.value)} step={300} error={errors.ends_at} />
        <div className="field">
          <label className="field__label" htmlFor="event-duration">
            Durée
          </label>
          <select id="event-duration" className="select" value={minutes} onChange={(event) => setMinutes(Number(event.target.value))}>
            {DURATIONS.map((value) => (
              <option key={value} value={value}>
                {value < 60 ? `${value} min` : value % 60 === 0 ? `${value / 60} h` : `${Math.floor(value / 60)} h ${value % 60}`}
              </option>
            ))}
          </select>
        </div>
        <div className="field">
          <label className="field__label" htmlFor="event-language">
            Langue
          </label>
          <select id="event-language" className="select" value={language} onChange={(event) => setLanguage(event.target.value)}>
            <option value="fr">Français</option>
            <option value="en">Anglais</option>
          </select>
        </div>
      </div>
      <TextField
        label="Lien de la visioconférence"
        type="url"
        placeholder="Lien Zoom, Google Meet, Teams…"
        value={onlineUrl}
        onChange={(event) => setOnlineUrl(event.target.value)}
        error={errors.online_url}
        hint="Ce lien n'est visible que des participants inscrits, dans leur espace. Il n'est jamais envoyé par email."
      />
      <TextField label="Nombre de places (facultatif)" type="number" min={1} placeholder="Illimité si vide (300 au plus)" value={capacity} onChange={(event) => setCapacity(event.target.value)} error={errors.capacity} />
      <MediaUpload purpose="event_cover" label="Image de couverture (facultatif, 960 × 540 px au moins)" onUploaded={setCover} />
      <p className="note">
        <span className="note__icon">
          <Icon name="lock" size={16} />
        </span>
        Votre webinar sera relu par notre équipe avant d&apos;être publié. Assurez-vous que le contenu apporte une réelle valeur ajoutée aux entrepreneurs.
      </p>
      <p className="disclaimer">
        Les webinars sont informatifs et ne remplacent pas un conseil personnalisé. Loopartner peut refuser une proposition qui ne respecte pas ses CGU.
      </p>
      <FormError message={error} />
      {notice ? <p className="alert alert--success">{notice}</p> : null}
      <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 16 }}>
        <button
          type="button"
          className="btn btn--outline btn--lg"
          disabled={busy}
          onClick={async () => {
            setBusy(true);
            try {
              await save();
              setNotice('Brouillon enregistré.');
            } catch (exception) {
              showErrors(exception);
            } finally {
              setBusy(false);
            }
          }}
        >
          Enregistrer le brouillon
        </button>
        <button type="submit" className="btn btn--lime btn--lg" disabled={busy}>
          Envoyer pour validation
        </button>
      </div>
    </form>
  );
}
