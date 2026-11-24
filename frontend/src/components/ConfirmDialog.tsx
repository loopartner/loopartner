'use client';

import { useState, type ReactNode } from 'react';
import { ApiError } from '@/lib/api';
import { Dialog } from './Dialog';
import { FormError } from './Field';

/** Confirmation d'une action qui ne s'annule pas (refuser, retirer, bloquer). */
export function ConfirmDialog({
  title,
  children,
  confirmLabel,
  onConfirm,
  onClose,
}: {
  title: string;
  children: ReactNode;
  confirmLabel: string;
  onConfirm: () => Promise<void>;
  onClose: () => void;
}) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  return (
    <Dialog title={title} onClose={onClose}>
      <div className="form-stack">
        <div style={{ fontFamily: 'var(--font-text)', lineHeight: 1.6 }}>{children}</div>
        <FormError message={error} />
        <div style={{ display: 'flex', gap: 12, justifyContent: 'flex-end', flexWrap: 'wrap' }}>
          <button type="button" className="btn btn--outline" onClick={onClose} disabled={busy}>
            Annuler
          </button>
          <button
            type="button"
            className="btn btn--dark"
            disabled={busy}
            onClick={async () => {
              setBusy(true);
              setError(null);
              try {
                await onConfirm();
                onClose();
              } catch (exception) {
                setError(exception instanceof ApiError ? exception.message : 'Action impossible.');
                setBusy(false);
              }
            }}
          >
            {busy ? 'Un instant…' : confirmLabel}
          </button>
        </div>
      </div>
    </Dialog>
  );
}
