'use client';

import { useId, useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { Icon } from './Icon';

export type MediaPurpose = 'article_cover' | 'event_cover' | 'provider_logo' | 'provider_banner' | 'offer_image' | 'avatar';

/** Dimensions minimales de chaque usage (backend/app/Support/Media/MediaPurpose.php). */
const MIN_SIZES: Record<MediaPurpose, string> = {
  avatar: '128 × 128 px',
  provider_logo: '128 × 128 px',
  provider_banner: '1200 × 300 px',
  offer_image: '800 × 450 px',
  event_cover: '960 × 540 px',
  article_cover: '1200 × 630 px',
};

/**
 * Envoi d'une image par la brique média (`POST /media`, JPEG, PNG ou WebP, 5 Mo au plus).
 * Renvoie l'uuid du média (et son URL, pour un aperçu), à transmettre ensuite au module ;
 * `null` quand l'image est retirée ou que l'envoi échoue.
 */
export function MediaUpload({
  purpose,
  label,
  onUploaded,
  initialUrl = null,
  shape = 'cover',
  removable = false,
  hint,
  error: externalError,
}: {
  purpose: MediaPurpose;
  label: string;
  onUploaded: (uuid: string | null, url: string | null) => void;
  initialUrl?: string | null;
  shape?: 'cover' | 'banner' | 'round';
  removable?: boolean;
  hint?: string;
  error?: string | null;
}) {
  const [preview, setPreview] = useState<string | null>(initialUrl);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const inputId = `upload-${purpose}-${useId().replace(/:/g, '')}`;

  async function upload(file: File) {
    setError(null);
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
      setError('Formats acceptés : JPEG, PNG ou WebP.');
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      setError('Image trop lourde : 5 Mo au plus.');
      return;
    }
    setBusy(true);
    try {
      const form = new FormData();
      form.append('file', file);
      form.append('purpose', purpose);
      const response = await api<{ data?: { uuid: string; url: string }; uuid?: string; url?: string }>('media', { method: 'POST', body: form });
      const media = response.data ?? (response as { uuid: string; url: string });
      setPreview(media.url);
      onUploaded(media.uuid, media.url);
    } catch (exception) {
      setError(exception instanceof ApiError ? (exception.field('file') ?? exception.message) : "L'envoi a échoué.");
    } finally {
      setBusy(false);
    }
  }

  const shown = externalError ?? error;

  return (
    <div className="field">
      <span className="field__label">{label}</span>
      <label className={`dropzone dropzone--${shape}${preview ? ' dropzone--filled' : ''}`} htmlFor={inputId}>
        {preview ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={preview} alt="Aperçu de l'image envoyée" />
        ) : (
          <span className="dropzone__icon">
            <Icon name="image" />
          </span>
        )}
        <span className="dropzone__text">
          {busy ? 'Envoi en cours…' : preview ? "Cliquez pour changer d'image" : `Ajouter une image (JPEG, PNG ou WebP, ${MIN_SIZES[purpose]} au moins, 5 Mo au plus)`}
        </span>
        <input
          id={inputId}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="visually-hidden"
          onChange={(event) => {
            const file = event.target.files?.[0];
            if (file) void upload(file);
            event.target.value = '';
          }}
        />
      </label>
      {hint ? <span className="field__hint">{hint}</span> : null}
      {removable && preview ? (
        <button
          type="button"
          className="report-link"
          style={{ alignSelf: 'flex-start' }}
          onClick={() => {
            setPreview(null);
            onUploaded(null, null);
          }}
        >
          Retirer l&apos;image
        </button>
      ) : null}
      {shown ? <span className="field__error">{shown}</span> : null}
    </div>
  );
}
