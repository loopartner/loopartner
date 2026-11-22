'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useCallback, useEffect, useState } from 'react';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { counterpartName } from '@/lib/counterpart';
import { formatDate, formatTime, initials } from '@/lib/format';
import type { Conversation, MatchRequest, Paginated } from '@/lib/types';
import { RequestPanel } from './RequestPanel';
import { Thread } from './Thread';

const LIST_REFRESH_MS = 30_000;

type Item =
  | { kind: 'conversation'; key: string; at: string; conversation: Conversation }
  | { kind: 'request'; key: string; at: string; request: MatchRequest };

/**
 * Messagerie unique, comme une boîte de réception : les propositions et demandes de contact en
 * attente y arrivent comme des conversations (paramètre « d »), à accepter ou refuser sur place ;
 * une fois acceptées, elles deviennent des conversations (paramètre « c »).
 */
export function Inbox() {
  const params = useSearchParams();
  const router = useRouter();
  const selectedConversation = params.get('c');
  const selectedRequest = params.get('d');
  const [items, setItems] = useState<Item[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);
  const reload = useCallback(() => setReloadKey((key) => key + 1), []);

  useEffect(() => {
    let active = true;
    const load = () =>
      Promise.all([
        api<Paginated<Conversation>>('me/conversations', { query: { per_page: 50 } }),
        api<Paginated<MatchRequest>>('me/match-requests', { query: { box: 'received', status: 'pending', per_page: 50 } }),
        api<Paginated<MatchRequest>>('me/match-requests', { query: { box: 'sent', status: 'pending', per_page: 50 } }),
      ])
        .then(([conversations, received, sent]) => {
          if (!active) return;
          const merged: Item[] = [
            ...conversations.data.map((conversation) => ({
              kind: 'conversation' as const,
              key: `c:${conversation.uuid}`,
              at: conversation.last_activity_at ?? conversation.created_at,
              conversation,
            })),
            ...[...received.data, ...sent.data].map((request) => ({ kind: 'request' as const, key: `d:${request.uuid}`, at: request.created_at, request })),
          ];
          // Propositions reçues en attente d'abord, puis tout le reste du plus récent au plus ancien.
          merged.sort((a, b) => {
            const pendingA = a.kind === 'request' && a.request.role === 'recipient' ? 1 : 0;
            const pendingB = b.kind === 'request' && b.request.role === 'recipient' ? 1 : 0;
            return pendingB - pendingA || b.at.localeCompare(a.at);
          });
          setItems(merged);
          setError(null);
        })
        .catch((exception) => active && setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
    void load();
    const timer = window.setInterval(() => document.visibilityState === 'visible' && void load(), LIST_REFRESH_MS);
    const onRead = () => void load();
    window.addEventListener('loopartner:messages-read', onRead);
    return () => {
      active = false;
      window.clearInterval(timer);
      window.removeEventListener('loopartner:messages-read', onRead);
    };
  }, [reloadKey]);

  const currentConversation = items?.find((item) => item.kind === 'conversation' && item.conversation.uuid === selectedConversation);
  const currentRequest = items?.find((item) => item.kind === 'request' && item.request.uuid === selectedRequest);
  const current = currentConversation ?? currentRequest ?? null;
  const first = items?.[0];
  const firstHref = first ? (first.kind === 'conversation' ? `/messages?c=${first.conversation.uuid}` : `/messages?d=${first.request.uuid}`) : null;
  const nothingSelected = !selectedConversation && !selectedRequest;

  // Sur grand écran, le premier élément s'ouvre de lui-même.
  useEffect(() => {
    if (nothingSelected && firstHref && window.matchMedia('(min-width: 901px)').matches) router.replace(firstHref);
  }, [nothingSelected, firstHref, router]);

  if (error) return <div className="card empty">{error}</div>;
  if (!items) return <div className="skeleton" style={{ height: 420 }} />;

  if (items.length === 0) {
    return (
      <div className="card empty">
        <Icon name="message" size={32} />
        <p>Aucun message pour le moment. Les propositions des prestataires et vos conversations apparaîtront ici.</p>
        <Link href="/projets" className="btn btn--lime">
          Voir les projets
        </Link>
      </div>
    );
  }

  return (
    <div className={`inbox${current ? ' inbox--thread' : ''}`}>
      <div className="card inbox__list-card" style={{ padding: 8 }}>
        <div style={{ padding: '8px 12px' }}>
          <strong>Messages</strong>
        </div>
        <nav className="inbox__list" aria-label="Conversations">
          {items.map((item) => (item.kind === 'conversation' ? <ConversationItem key={item.key} conversation={item.conversation} selected={item.conversation.uuid === selectedConversation} onOpen={() => router.push(`/messages?c=${item.conversation.uuid}`)} /> : <RequestItem key={item.key} request={item.request} selected={item.request.uuid === selectedRequest} onOpen={() => router.push(`/messages?d=${item.request.uuid}`)} />))}
        </nav>
      </div>

      {current?.kind === 'conversation' ? (
        <Thread key={current.conversation.uuid} conversation={current.conversation} onChanged={reload} />
      ) : current?.kind === 'request' ? (
        <RequestPanel
          key={current.request.uuid}
          request={current.request}
          onChanged={(conversationUuid) => {
            reload();
            window.dispatchEvent(new Event('loopartner:messages-read'));
            router.replace(conversationUuid ? `/messages?c=${conversationUuid}` : '/messages');
          }}
        />
      ) : (
        <div className="card thread thread--empty">{nothingSelected ? 'Choisissez une conversation.' : 'Cette conversation n’existe plus ou a été fermée.'}</div>
      )}
    </div>
  );
}

function Avatar({ name, logo }: { name: string; logo: string | null | undefined }) {
  return logo ? (
    // eslint-disable-next-line @next/next/no-img-element
    <img src={logo} alt="" className="avatar" />
  ) : (
    <span className="avatar">{initials(name)}</span>
  );
}

function ConversationItem({ conversation, selected, onOpen }: { conversation: Conversation; selected: boolean; onOpen: () => void }) {
  const name = counterpartName(conversation.counterpart);
  const last = conversation.last_message;
  return (
    <button type="button" className="inbox__item" aria-current={selected} onClick={onOpen}>
      <Avatar name={name} logo={conversation.counterpart.provider?.logo_url} />
      <span className="inbox__item-body">
        <span className="inbox__item-top">
          <span className="inbox__item-name">{name}</span>
          {last ? <small style={{ color: 'var(--muted)', flexShrink: 0 }}>{shortDate(last.sent_at)}</small> : null}
        </span>
        {conversation.project ? <span className="inbox__item-project">{conversation.project.title}</span> : null}
        <span className="inbox__item-excerpt" style={conversation.unread_count > 0 ? { color: 'var(--ink)', fontWeight: 600 } : undefined}>
          {last ? `${last.mine ? 'Vous : ' : ''}${last.excerpt ?? 'Message masqué'}` : 'Demande acceptée : écrivez votre premier message'}
        </span>
      </span>
      {conversation.unread_count > 0 ? <span className="badge">{conversation.unread_count}</span> : null}
    </button>
  );
}

function RequestItem({ request, selected, onOpen }: { request: MatchRequest; selected: boolean; onOpen: () => void }) {
  const name = counterpartName(request.counterpart);
  const received = request.role === 'recipient';
  const label = received ? (request.type === 'proposal' ? 'Nouvelle proposition' : 'Nouvelle demande') : 'En attente de réponse';
  return (
    <button type="button" className="inbox__item" aria-current={selected} onClick={onOpen}>
      <Avatar name={name} logo={request.counterpart.provider?.logo_url} />
      <span className="inbox__item-body">
        <span className="inbox__item-top">
          <span className="inbox__item-name">{name}</span>
          <small style={{ color: 'var(--muted)', flexShrink: 0 }}>{shortDate(request.created_at)}</small>
        </span>
        {request.project ? <span className="inbox__item-project">{request.project.title}</span> : null}
        <span className="inbox__item-excerpt" style={received ? { color: 'var(--ink)', fontWeight: 600 } : undefined}>
          {received ? '' : 'Vous : '}
          {request.message ?? 'Message masqué'}
        </span>
        <span className={`inbox__tag${received ? ' inbox__tag--new' : ''}`}>{label}</span>
      </span>
      {received ? <span className="badge">1</span> : null}
    </button>
  );
}

function shortDate(iso: string): string {
  const date = new Date(iso);
  const today = new Date();
  return date.toDateString() === today.toDateString() ? formatTime(iso) : formatDate(iso, { day: 'numeric', month: 'short' });
}
