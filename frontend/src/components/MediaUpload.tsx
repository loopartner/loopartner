'use client';

import { useState } from 'react';
import { api, ApiError } from '@/lib/api';
import { Icon } from './Icon';

/**
 * Envoi d'une image par la brique média (`POST /media`, JPEG, PNG ou WebP, 5 Mo au plus).
 * Renvoie l'uuid du média, à transmettre ensuite au module (champ `cover`).
 */
export function MediaUpload({
  purpose,
  label,
  onUploaded,
}: {
  purpose: 'article_cover' | 'event_cover';
  label: string;
  onUploaded: (uuid: string | null) => void;
}) {
  const [preview, setPreview] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const inputId = `upload-${purpose}`;

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
      onUploaded(media.uuid);
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.field('file') ?? exception.message : "L'envoi a échoué.");
      onUploaded(null);
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="field">
      <span className="field__label">{label}</span>
      <label className="dropzone" htmlFor={inputId}>
        {preview ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img src={preview} alt="Aperçu de l'image envoyée" />
        ) : (
          <span style={{ width: 44, height: 44, borderRadius: '50%', background: 'var(--pill)', display: 'grid', placeItems: 'center', margin: '0 auto 12px', color: 'var(--blue)' }}>
            <Icon name="image" />
          </span>
        )}
        {busy ? 'Envoi en cours…' : preview ? "Cliquez pour changer d'image" : 'Cliquez pour ajouter une image (JPEG, PNG ou WebP, 5 Mo au plus)'}
        <input
          id={inputId}
          type="file"
          accept="image/jpeg,image/png,image/webp"
          className="visually-hidden"
          onChange={(event) => {
            const file = event.target.files?.[0];
            if (file) void upload(file);
          }}
        />
      </label>
      {error ? <span className="field__error">{error}</span> : null}
    </div>
  );
}
