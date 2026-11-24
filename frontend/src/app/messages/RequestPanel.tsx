'use client';

import Link from 'next/link';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Icon } from '@/components/Icon';
import { PrivateReportDialog } from '@/components/PrivateReportDialog';
import { api, ApiError } from '@/lib/api';
import { counterpartDetail, counterpartName } from '@/lib/counterpart';
import { formatBudget, formatDate, formatTime } from '@/lib/format';
import type { MatchRequest } from '@/lib/types';
import { ChatAvatar } from './ChatAvatar';

/**
 * Proposition ou demande de contact en attente, affichée comme le début d'une conversation :
 * le destinataire l'accepte (la conversation s'ouvre et il peut répondre) ou la refuse ;
 * l'auteur attend la réponse et peut la retirer.
 */
export function RequestPanel({ request, onChanged }: { request: MatchRequest; onChanged: (conversationUuid: string | null) => void }) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [dialog, setDialog] = useState<'decline' | 'withdraw' | 'block' | 'report' | null>(null);
  const received = request.role === 'recipient';
  const name = counterpartName(request.counterpart);
  const detail = counterpartDetail(request.counterpart);
  const slug = request.counterpart.provider?.slug;

  async function accept() {
    setBusy(true);
    setError(null);
    try {
      const response = await api<{ data: MatchRequest }>(`me/match-requests/${request.uuid}/accept`, { method: 'POST' });
      onChanged(response.data.conversation?.uuid ?? null);
    } catch (exception) {
      setError(exception instanceof ApiError ? exception.message : 'Action impossible.');
      setBusy(false);
    }
  }

  return (
    <section className="card thread" aria-label={`Demande de ${name}`}>
      <div className="thread__head">
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
          <Link href="/messages" className="thread__back btn btn--ghost" aria-label="Retour aux messages" style={{ padding: 6 }}>
            <Icon name="arrowLeft" />
          </Link>
          <ChatAvatar name={name} logo={request.counterpart.provider?.logo_url} size="lg" />
          <div style={{ minWidth: 0 }}>
            <strong className="thread__name">{slug ? <Link href={`/prestataires/${slug}`}>{name}</Link> : name}</strong>
            <small className="thread__detail">{detail}</small>
            {request.project ? (
              <span className="thread__project">
                <Icon name="briefcase" size={13} /> {request.project.title}
              </span>
            ) : null}
          </div>
        </div>
        {received && !request.counterpart.deleted ? (
          <span style={{ display: 'inline-flex', gap: 16 }}>
            <button type="button" className="report-link" onClick={() => setDialog('report')}>
              <Icon name="flag" size={14} /> Signaler
            </button>
            <button type="button" className="report-link" onClick={() => setDialog('block')}>
              <Icon name="ban" size={14} /> Bloquer
            </button>
          </span>
        ) : null}
      </div>

      <div className="thread__messages">
        <span className="day-sep">{formatDate(request.created_at, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
        <div className={`bubble bubble--origin${received ? '' : ' bubble--mine'}`}>
          <span className="bubble__label">
            <Icon name={request.type === 'proposal' ? 'briefcase' : 'message'} size={14} />{' '}
            {request.type === 'proposal' ? (received ? 'Proposition reçue' : 'Votre proposition') : received ? 'Demande de contact' : 'Votre demande de contact'}</span>
          {request.message === null ? (
            <span className="bubble__text bubble__text--hidden">Message masqué par la modération</span>
          ) : (
            <span className="bubble__text">{request.message}</span>
          )}
          {request.price ? (
            <span className="bubble__price">Prix proposé · {formatBudget(request.price.amount, request.price.amount, request.price.currency)}</span>
          ) : null}
          <span className="bubble__meta">{formatTime(request.created_at)}</span>
        </div>
      </div>

      {received ? (
        <div className="request-bar">
          <p style={{ margin: 0 }}>
            {request.type === 'proposal'
              ? `${name} vous propose son aide. Acceptez pour lui répondre : votre nom lui sera alors montré.`
              : 'Acceptez pour répondre et ouvrir la conversation.'}
          </p>
          {error ? <p className="field__error">{error}</p> : null}
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
            <button type="button" className="btn btn--lime" onClick={() => void accept()} disabled={busy}>
              {busy ? 'Un instant…' : 'Accepter et répondre'}
            </button>
            <button type="button" className="btn btn--outline" onClick={() => setDialog('decline')} disabled={busy}>
              Refuser
            </button>
          </div>
        </div>
      ) : (
        <div className="request-bar">
          <p style={{ margin: 0 }}>
            En attente de la réponse de {name}.{request.expires_at ? ` Sans réponse, la demande expire le ${formatDate(request.expires_at)}.` : ''}
          </p>
          <div>
            <button type="button" className="btn btn--outline btn--sm" onClick={() => setDialog('withdraw')}>
              Retirer ma demande
            </button>
          </div>
        </div>
      )}
      <p className="disclaimer" style={{ margin: 0, padding: '0 24px 14px' }}>
        Vos coordonnées ne sont jamais montrées : tout passe par la messagerie. Ne payez jamais en dehors d&apos;un devis accepté.
      </p>

      {dialog === 'report' ? (
        <PrivateReportDialog path={`me/match-requests/${request.uuid}/report`} subject="cette demande" onClose={() => setDialog(null)} />
      ) : dialog ? (
        <ConfirmDialog
          title={dialog === 'decline' ? 'Refuser cette proposition ?' : dialog === 'withdraw' ? 'Retirer votre demande ?' : `Bloquer ${name} ?`}
          confirmLabel={dialog === 'decline' ? 'Refuser' : dialog === 'withdraw' ? 'Retirer' : 'Bloquer'}
          onClose={() => setDialog(null)}
          onConfirm={async () => {
            await api(`me/match-requests/${request.uuid}/${dialog}`, { method: 'POST' });
            onChanged(null);
          }}
        >
          <p>
            {dialog === 'decline'
              ? `${name} sera prévenu(e) que sa proposition n'a pas été retenue, sans motif.`
              : dialog === 'withdraw'
                ? 'Votre demande disparaîtra de la messagerie de son destinataire.'
                : 'Cette personne ne pourra plus vous écrire ni vous envoyer de demande, et celle-ci sera refusée.'}
          </p>
        </ConfirmDialog>
      ) : null}
    </section>
  );
}
