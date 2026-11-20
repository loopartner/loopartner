'use client';

import Link from 'next/link';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Icon } from '@/components/Icon';
import { Pagination } from '@/components/Pagination';
import { PrivateReportDialog } from '@/components/PrivateReportDialog';
import { api, ApiError } from '@/lib/api';
import { counterpartDetail, counterpartName, REQUEST_STATUS_LABELS } from '@/lib/counterpart';
import { formatBudget, formatDate, initials, timeAgo } from '@/lib/format';
import type { MatchRequest, MatchRequestStatus, Paginated } from '@/lib/types';

type Box = 'received' | 'sent';
type PendingAction = { kind: 'decline' | 'withdraw' | 'block' | 'report'; request: MatchRequest } | null;

const STATUS_FILTERS: Array<{ value: '' | MatchRequestStatus; label: string }> = [
  { value: '', label: 'Toutes' },
  { value: 'pending', label: 'En attente' },
  { value: 'accepted', label: 'Acceptées' },
  { value: 'declined', label: 'Refusées' },
  { value: 'withdrawn', label: 'Retirées' },
  { value: 'expired', label: 'Expirées' },
];

/** Demandes reçues et envoyées (GET /me/match-requests), avec les actions permises à chacun. */
export function RequestsBoard() {
  const params = useSearchParams();
  const router = useRouter();
  const pathname = usePathname();
  const box: Box = params.get('box') === 'sent' ? 'sent' : 'received';
  const status = (params.get('statut') ?? '') as '' | MatchRequestStatus;
  const page = Math.max(1, Number(params.get('page') ?? 1) || 1);

  const [data, setData] = useState<Paginated<MatchRequest> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const [pending, setPending] = useState<PendingAction>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    let active = true;
    api<Paginated<MatchRequest>>('me/match-requests', { query: { box, status: status || undefined, page, per_page: 10 } })
      .then((response) => {
        if (!active) return;
        setData(response);
        setError(null);
      })
      .catch((exception) => active && setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
    return () => {
      active = false;
    };
  }, [box, status, page, reloadKey]);

  const navigate = useCallback(
    (changes: Record<string, string | null>) => {
      const next = new URLSearchParams(params.toString());
      for (const [key, value] of Object.entries(changes)) {
        if (value) next.set(key, value);
        else next.delete(key);
      }
      router.push(`${pathname}?${next.toString()}`);
    },
    [params, pathname, router],
  );

  const reload = () => setReloadKey((key) => key + 1);

  async function accept(request: MatchRequest) {
    setBusy(request.uuid);
    setNotice(null);
    try {
      const response = await api<{ data: MatchRequest }>(`me/match-requests/${request.uuid}/accept`, { method: 'POST' });
      if (response.data.conversation) {
        router.push(`/messages?c=${response.data.conversation.uuid}`);
        return;
      }
      reload();
    } catch (exception) {
      setNotice(exception instanceof ApiError ? exception.message : 'Action impossible.');
    } finally {
      setBusy(null);
    }
  }

  const href = (target: number) => {
    const next = new URLSearchParams(params.toString());
    next.set('page', String(target));
    return `${pathname}?${next.toString()}`;
  };

  return (
    <div className="form-stack" style={{ gap: 24 }}>
      <div className="tabs" role="tablist" aria-label="Boîte">
        {(['received', 'sent'] as const).map((value) => (
          <button
            key={value}
            type="button"
            role="tab"
            className="tabs__tab"
            aria-selected={box === value}
            onClick={() => navigate({ box: value === 'received' ? null : 'sent', page: null })}
          >
            {value === 'received' ? 'Reçues' : 'Envoyées'}
          </button>
        ))}
        <select
          className="select select--pill"
          aria-label="Filtrer par statut"
          value={status}
          onChange={(event) => navigate({ statut: event.target.value || null, page: null })}
          style={{ marginLeft: 'auto' }}
        >
          {STATUS_FILTERS.map((option) => (
            <option key={option.value} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      {notice ? <p className="alert alert--error">{notice}</p> : null}

      {error ? (
        <div className="card empty">{error}</div>
      ) : !data ? (
        <div className="skeleton" style={{ height: 220 }} />
      ) : data.data.length === 0 ? (
        <div className="card empty">
          <Icon name="inbox" size={32} />
          <p>{box === 'received' ? 'Aucune demande reçue pour le moment.' : 'Vous n’avez envoyé aucune demande.'}</p>
          {box === 'sent' ? (
            <Link href="/prestataires" className="btn btn--lime">
              Trouver un prestataire
            </Link>
          ) : null}
        </div>
      ) : (
        <>
          {data.data.map((request) => (
            <RequestCard
              key={request.uuid}
              request={request}
              busy={busy === request.uuid}
              onAccept={() => void accept(request)}
              onAction={(kind) => setPending({ kind, request })}
            />
          ))}
          <Pagination current={data.meta.current_page} last={data.meta.last_page} href={href} />
        </>
      )}

      <p className="disclaimer">
        Une demande sans réponse expire après 30 jours. Vos coordonnées ne sont jamais montrées : tout passe par la messagerie de Loopartner. Ne payez
        jamais en dehors d&apos;un devis accepté.
      </p>

      {pending?.kind === 'report' ? (
        <PrivateReportDialog path={`me/match-requests/${pending.request.uuid}/report`} subject="cette demande" onClose={() => setPending(null)} />
      ) : pending ? (
        <ConfirmDialog
          title={pending.kind === 'decline' ? 'Refuser la demande ?' : pending.kind === 'withdraw' ? 'Retirer votre demande ?' : 'Bloquer cette personne ?'}
          confirmLabel={pending.kind === 'decline' ? 'Refuser' : pending.kind === 'withdraw' ? 'Retirer' : 'Bloquer'}
          onClose={() => setPending(null)}
          onConfirm={async () => {
            const path = `me/match-requests/${pending.request.uuid}/${pending.kind}`;
            await api(path, { method: 'POST' });
            reload();
          }}
        >
          {pending.kind === 'decline' ? (
            <p>{counterpartName(pending.request.counterpart)} sera prévenu(e) par email que sa demande n&apos;a pas été retenue, sans motif.</p>
          ) : pending.kind === 'withdraw' ? (
            <p>La demande ne sera plus visible par son destinataire. Vous pourrez en envoyer une nouvelle plus tard.</p>
          ) : (
            <p>
              Cette personne ne pourra plus vous écrire ni vous envoyer de demande, et sa demande en attente sera refusée sans email. Pensez à signaler le
              contenu s&apos;il enfreint les règles.
            </p>
          )}
        </ConfirmDialog>
      ) : null}
    </div>
  );
}

function RequestCard({
  request,
  busy,
  onAccept,
  onAction,
}: {
  request: MatchRequest;
  busy: boolean;
  onAccept: () => void;
  onAction: (kind: 'decline' | 'withdraw' | 'block' | 'report') => void;
}) {
  const name = counterpartName(request.counterpart);
  const detail = counterpartDetail(request.counterpart);
  const isRecipient = request.role === 'recipient';
  const title =
    request.type === 'proposal'
      ? isRecipient
        ? `Proposition sur votre projet « ${request.project?.title ?? 'projet supprimé'} »`
        : `Votre proposition sur « ${request.project?.title ?? 'projet supprimé'} »`
      : isRecipient
        ? 'Demande de contact'
        : 'Votre demande de contact';
  const logo = request.counterpart.provider?.logo_url;

  return (
    <article className="card request">
      <div className="request__head">
        <div className="author">
          {logo ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={logo} alt="" className="avatar avatar--lg" />
          ) : (
            <span className="avatar avatar--lg">{initials(name)}</span>
          )}
          <div>
            <strong style={{ display: 'block' }}>
              {request.counterpart.provider?.slug ? <Link href={`/prestataires/${request.counterpart.provider.slug}`}>{name}</Link> : name}
            </strong>
            <small style={{ color: 'var(--muted)' }}>{[detail, timeAgo(request.created_at)].filter(Boolean).join(' · ')}</small>
          </div>
        </div>
        <span className={`status status--${request.status}`}>{REQUEST_STATUS_LABELS[request.status]}</span>
      </div>

      <h2 style={{ margin: 0, fontSize: 18 }}>{title}</h2>

      {request.message === null ? (
        <p className="request__message" style={{ fontStyle: 'italic', color: 'var(--muted)' }}>
          Ce message a été masqué par la modération.
        </p>
      ) : (
        <p className="request__message">{request.message}</p>
      )}

      <div className="project-card__meta">
        {request.price ? <span>Prix proposé : {formatBudget(request.price.amount, request.price.amount, request.price.currency)}</span> : null}
        {request.type === 'contact' && request.project ? <span>À propos de « {request.project.title} »</span> : null}
        {request.expires_at ? <span>Expire le {formatDate(request.expires_at)}</span> : null}
      </div>

      <div className="request__actions">
        {request.status === 'accepted' && request.conversation ? (
          <Link href={`/messages?c=${request.conversation.uuid}`} className="btn btn--dark">
            <Icon name="message" size={18} /> Ouvrir la conversation
          </Link>
        ) : null}
        {isRecipient && request.status === 'pending' ? (
          <>
            <button type="button" className="btn btn--lime" onClick={onAccept} disabled={busy}>
              {busy ? 'Un instant…' : 'Accepter et répondre'}
            </button>
            <button type="button" className="btn btn--outline" onClick={() => onAction('decline')} disabled={busy}>
              Refuser
            </button>
          </>
        ) : null}
        {!isRecipient && request.status === 'pending' ? (
          <button type="button" className="btn btn--outline" onClick={() => onAction('withdraw')}>
            Retirer ma demande
          </button>
        ) : null}
        {isRecipient && !request.counterpart.deleted ? (
          <span style={{ marginLeft: 'auto', display: 'inline-flex', gap: 16 }}>
            <button type="button" className="report-link" onClick={() => onAction('report')}>
              <Icon name="flag" size={14} /> Signaler
            </button>
            {request.status === 'pending' ? (
              <button type="button" className="report-link" onClick={() => onAction('block')}>
                <Icon name="ban" size={14} /> Bloquer
              </button>
            ) : null}
          </span>
        ) : null}
      </div>
    </article>
  );
}
