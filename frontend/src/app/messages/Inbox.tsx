'use client';

import Link from 'next/link';
import { useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';
import { Icon } from '@/components/Icon';
import { api, ApiError } from '@/lib/api';
import { counterpartName } from '@/lib/counterpart';
import { formatDate, formatTime, initials } from '@/lib/format';
import type { Conversation, Paginated } from '@/lib/types';
import { Thread } from './Thread';

const LIST_REFRESH_MS = 30_000;

/** Liste des conversations à gauche, conversation ouverte à droite (paramètre « c »). */
export function Inbox() {
  const params = useSearchParams();
  const router = useRouter();
  const selected = params.get('c');
  const [conversations, setConversations] = useState<Conversation[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [reloadKey, setReloadKey] = useState(0);

  useEffect(() => {
    let active = true;
    const load = () =>
      api<Paginated<Conversation>>('me/conversations', { query: { per_page: 50 } })
        .then((response) => {
          if (!active) return;
          setConversations(response.data);
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

  const current = conversations?.find((conversation) => conversation.uuid === selected) ?? null;
  const first = conversations?.[0]?.uuid;

  // Sur grand écran, la conversation la plus récente s'ouvre d'elle-même.
  useEffect(() => {
    if (!selected && first && window.matchMedia('(min-width: 901px)').matches) router.replace(`/messages?c=${first}`);
  }, [selected, first, router]);

  if (error) return <div className="card empty">{error}</div>;
  if (!conversations) return <div className="skeleton" style={{ height: 420 }} />;

  if (conversations.length === 0) {
    return (
      <div className="card empty">
        <Icon name="message" size={32} />
        <p>Aucune conversation pour le moment. Une conversation s&apos;ouvre quand une demande de mise en relation est acceptée.</p>
        <Link href="/demandes" className="btn btn--lime">
          Voir mes demandes
        </Link>
      </div>
    );
  }

  return (
    <div className={`inbox${current ? ' inbox--thread' : ''}`}>
      <div className="card inbox__list-card" style={{ padding: 8 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '8px 12px' }}>
          <strong>Conversations</strong>
          <Link href="/demandes" className="link-arrow" style={{ fontSize: 14 }}>
            Demandes <Icon name="arrowRight" size={14} />
          </Link>
        </div>
        <nav className="inbox__list" aria-label="Conversations">
          {conversations.map((conversation) => {
            const name = counterpartName(conversation.counterpart);
            const logo = conversation.counterpart.provider?.logo_url;
            const last = conversation.last_message;
            return (
              <button
                key={conversation.uuid}
                type="button"
                className="inbox__item"
                aria-current={conversation.uuid === selected}
                onClick={() => router.push(`/messages?c=${conversation.uuid}`)}
              >
                {logo ? (
                  // eslint-disable-next-line @next/next/no-img-element
                  <img src={logo} alt="" className="avatar" />
                ) : (
                  <span className="avatar">{initials(name)}</span>
                )}
                <span className="inbox__item-body">
                  <span className="inbox__item-top">
                    <span className="inbox__item-name">{name}</span>
                    {last ? <small style={{ color: 'var(--muted)', flexShrink: 0 }}>{shortDate(last.sent_at)}</small> : null}
                  </span>
                  {conversation.project ? <span className="inbox__item-project">{conversation.project.title}</span> : null}
                  <span className="inbox__item-excerpt" style={conversation.unread_count > 0 ? { color: 'var(--ink)', fontWeight: 600 } : undefined}>
                    {last ? `${last.mine ? 'Vous : ' : ''}${last.excerpt ?? 'Message masqué'}` : 'Aucun message pour le moment'}
                  </span>
                </span>
                {conversation.unread_count > 0 ? <span className="badge">{conversation.unread_count}</span> : null}
              </button>
            );
          })}
        </nav>
      </div>

      {current ? (
        <Thread key={current.uuid} conversation={current} onChanged={() => setReloadKey((key) => key + 1)} />
      ) : (
        <div className="card thread thread--empty">
          {selected ? 'Conversation introuvable.' : 'Choisissez une conversation.'}
        </div>
      )}
    </div>
  );
}

function shortDate(iso: string): string {
  const date = new Date(iso);
  const today = new Date();
  return date.toDateString() === today.toDateString() ? formatTime(iso) : formatDate(iso, { day: 'numeric', month: 'short' });
}
