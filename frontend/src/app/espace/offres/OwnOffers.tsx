'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { formatDate } from '@/lib/format';
import { offerPrice, SELLER_FIELD_LABELS } from '@/lib/offers';
import type { OwnOffer, Paginated } from '@/lib/types';

const STATUS: Record<OwnOffer['status'], { label: string; className: string }> = {
  draft: { label: 'Brouillon', className: 'status--pending_review' },
  published: { label: 'Publiée', className: 'status--published' },
  unpublished: { label: 'Retirée', className: 'status--withdrawn' },
};

/** Offres du prestataire (GET /me/offers) : publier, retirer, modifier, supprimer. */
export function OwnOffers() {
  const [offers, setOffers] = useState<OwnOffer[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [missing, setMissing] = useState<string[] | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [deleting, setDeleting] = useState<OwnOffer | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<Paginated<OwnOffer>>('me/offers', { query: { per_page: 50 } })
      .then((response) => active && setOffers(response.data))
      .catch((exception) => active && setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
    return () => {
      active = false;
    };
  }, [reloadKey]);

  async function toggle(offer: OwnOffer) {
    setBusy(offer.uuid);
    setError(null);
    setMissing(null);
    try {
      await api(`me/offers/${offer.uuid}/${offer.status === 'published' ? 'unpublish' : 'publish'}`, { method: 'POST' });
      setReloadKey((key) => key + 1);
    } catch (exception) {
      if (exception instanceof ApiError && exception.code === 'seller_profile_incomplete') {
        setMissing(Array.isArray(exception.body.missing) ? (exception.body.missing as string[]) : []);
      } else {
        setError(exception instanceof ApiError ? exception.message : 'Action impossible.');
      }
    } finally {
      setBusy(null);
    }
  }

  return (
    <div className="form-stack" style={{ gap: 24 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', alignItems: 'center' }}>
        <Link href="/espace/identite" className="link-arrow">
          Mon identité professionnelle <Icon name="arrowRight" size={16} />
        </Link>
        <Link href="/espace/offres/nouvelle" className="btn btn--lime">
          <Icon name="plus" size={18} /> Nouvelle offre
        </Link>
      </div>

      {missing ? (
        <div className="alert alert--error">
          Pour publier une offre, complétez d&apos;abord votre identité professionnelle
          {missing.length > 0 ? ` (${missing.map((key) => SELLER_FIELD_LABELS[key] ?? key).join(', ')})` : ''}.{' '}
          <Link href="/espace/identite">La compléter</Link>
        </div>
      ) : null}
      {error ? <p className="alert alert--error">{error}</p> : null}

      {!offers ? (
        <div className="skeleton" style={{ height: 200 }} />
      ) : offers.length === 0 ? (
        <div className="card empty">
          <p>Vous n&apos;avez pas encore d&apos;offre. Une offre présente une prestation précise avec un prix indicatif.</p>
          <Link href="/espace/offres/nouvelle" className="btn btn--lime">
            Créer ma première offre
          </Link>
        </div>
      ) : (
        <div className="card" style={{ padding: 0, overflowX: 'auto' }}>
          <table className="table">
            <thead>
              <tr>
                <th>Offre</th>
                <th>Prix indicatif</th>
                <th>Statut</th>
                <th>
                  <span className="visually-hidden">Actions</span>
                </th>
              </tr>
            </thead>
            <tbody>
              {offers.map((offer) => (
                <tr key={offer.uuid}>
                  <td>
                    <strong>{offer.title}</strong>
                    <small style={{ display: 'block', color: 'var(--muted)' }}>
                      {offer.category?.name}
                      {offer.published_at ? ` · publiée le ${formatDate(offer.published_at)}` : ''}
                    </small>
                    {offer.moderation?.restricted ? <small style={{ display: 'block', color: 'var(--danger)' }}>Masquée par la modération</small> : null}
                  </td>
                  <td>{offerPrice(offer.pricing_mode, offer.price_cents, offer.currency)}</td>
                  <td>
                    <span className={`status ${STATUS[offer.status].className}`}>{STATUS[offer.status].label}</span>
                  </td>
                  <td style={{ whiteSpace: 'nowrap', textAlign: 'right' }}>
                    {offer.status === 'published' && offer.slug ? (
                      <Link href={`/offres/${offer.slug}`} className="btn btn--ghost btn--sm">
                        Voir
                      </Link>
                    ) : null}
                    <Link href={`/espace/offres/${offer.uuid}`} className="btn btn--ghost btn--sm">
                      Modifier
                    </Link>
                    <button type="button" className="btn btn--outline btn--sm" disabled={busy === offer.uuid} onClick={() => void toggle(offer)}>
                      {offer.status === 'published' ? 'Retirer' : 'Publier'}
                    </button>
                    <button type="button" className="btn btn--ghost btn--sm" onClick={() => setDeleting(offer)} aria-label={`Supprimer ${offer.title}`}>
                      <Icon name="close" size={16} />
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="disclaimer">
        Les offres sont publiées sans validation préalable et peuvent être signalées. Le prix affiché est indicatif et TTC ; le prix définitif figure
        dans le devis.
      </p>

      {deleting ? (
        <ConfirmDialog
          title="Supprimer cette offre ?"
          confirmLabel="Supprimer"
          onClose={() => setDeleting(null)}
          onConfirm={async () => {
            await api(`me/offers/${deleting.uuid}`, { method: 'DELETE' });
            setReloadKey((key) => key + 1);
          }}
        >
          <p>« {deleting.title} » sera supprimée définitivement.</p>
        </ConfirmDialog>
      ) : null}
    </div>
  );
}
