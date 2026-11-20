'use client';

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Icon } from '@/components/Icon';
import { PrivateReportDialog } from '@/components/PrivateReportDialog';
import { api, ApiError } from '@/lib/api';
import { counterpartDetail, counterpartName } from '@/lib/counterpart';
import { formatDate, formatTime } from '@/lib/format';
import type { ChatMessage, Conversation, Paginated } from '@/lib/types';

const POLL_MS = 10_000;
const MAX_LENGTH = 5000;

/**
 * Conversation ouverte : messages du plus ancien au plus récent, nouveaux messages récupérés
 * toutes les 10 secondes avec « after », marqueur de lecture avancé à chaque nouveau message.
 * Le texte des messages est affiché tel quel (jamais de HTML, jamais de lien rendu cliquable).
 */
export function Thread({ conversation, onChanged }: { conversation: Conversation; onChanged: () => void }) {
  const [messages, setMessages] = useState<ChatMessage[] | null>(null);
  const [olderPage, setOlderPage] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [draft, setDraft] = useState('');
  const [sending, setSending] = useState(false);
  const [sendError, setSendError] = useState<string | null>(null);
  const [reporting, setReporting] = useState<ChatMessage | null>(null);
  const [blockDialog, setBlockDialog] = useState(false);
  const scroller = useRef<HTMLDivElement>(null);
  const lastUuid = useRef<string | null>(null);
  const stickToBottom = useRef(true);
  const initialUnread = useRef(conversation.unread_count);
  const loaded = messages !== null;

  const markRead = useCallback(
    (upTo: string | null) => {
      if (!upTo) return;
      api(`me/conversations/${conversation.uuid}/read`, { method: 'POST', body: { up_to: upTo } })
        .then(() => window.dispatchEvent(new Event('loopartner:messages-read')))
        .catch(() => undefined);
    },
    [conversation.uuid],
  );

  // Premier chargement : la page la plus récente.
  useEffect(() => {
    let active = true;
    api<Paginated<ChatMessage>>(`me/conversations/${conversation.uuid}/messages`, { query: { per_page: 50 } })
      .then((response) => {
        if (!active) return;
        const ordered = [...response.data].reverse();
        setMessages(ordered);
        setOlderPage(response.meta.current_page < response.meta.last_page ? 2 : null);
        lastUuid.current = ordered.at(-1)?.uuid ?? null;
        if (initialUnread.current > 0) markRead(lastUuid.current);
      })
      .catch((exception) => active && setError(exception instanceof ApiError ? exception.message : 'Chargement impossible.'));
    return () => {
      active = false;
    };
  }, [conversation.uuid, markRead]);

  // Nouveaux messages.
  useEffect(() => {
    if (!loaded) return;
    const timer = window.setInterval(() => {
      if (document.visibilityState !== 'visible') return;
      api<{ data: ChatMessage[] }>(`me/conversations/${conversation.uuid}/messages`, { query: { after: lastUuid.current ?? undefined } })
        .then((response) => {
          const fresh = lastUuid.current ? response.data : [...response.data].reverse();
          if (fresh.length === 0) return;
          setMessages((current) => {
            const known = new Set((current ?? []).map((message) => message.uuid));
            return [...(current ?? []), ...fresh.filter((message) => !known.has(message.uuid))];
          });
          lastUuid.current = fresh.at(-1)?.uuid ?? lastUuid.current;
          if (fresh.some((message) => !message.mine)) markRead(lastUuid.current);
        })
        .catch(() => undefined);
    }, POLL_MS);
    return () => window.clearInterval(timer);
  }, [conversation.uuid, loaded, markRead]);

  // Reste en bas tant que l'utilisateur n'a pas remonté le fil.
  useEffect(() => {
    const element = scroller.current;
    if (element && stickToBottom.current) element.scrollTop = element.scrollHeight;
  }, [messages]);

  async function loadOlder() {
    if (!olderPage) return;
    const response = await api<Paginated<ChatMessage>>(`me/conversations/${conversation.uuid}/messages`, { query: { per_page: 50, page: olderPage } });
    stickToBottom.current = false;
    setMessages((current) => [...[...response.data].reverse(), ...(current ?? [])]);
    setOlderPage(response.meta.current_page < response.meta.last_page ? olderPage + 1 : null);
  }

  async function send() {
    const body = draft.trim();
    if (!body) return;
    setSending(true);
    setSendError(null);
    try {
      const response = await api<{ data: ChatMessage }>(`me/conversations/${conversation.uuid}/messages`, { method: 'POST', body: { body } });
      stickToBottom.current = true;
      setMessages((current) => [...(current ?? []), response.data]);
      lastUuid.current = response.data.uuid;
      setDraft('');
      onChanged();
    } catch (exception) {
      setSendError(exception instanceof ApiError ? (exception.field('body') ?? exception.message) : 'Envoi impossible.');
    } finally {
      setSending(false);
    }
  }

  const name = counterpartName(conversation.counterpart);
  const detail = counterpartDetail(conversation.counterpart);
  const slug = conversation.counterpart.provider?.slug;

  return (
    <section className="card thread" aria-label={`Conversation avec ${name}`}>
      <div className="thread__head">
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
          <Link href="/messages" className="thread__back btn btn--ghost" aria-label="Retour aux conversations" style={{ padding: 6 }}>
            <Icon name="arrowLeft" />
          </Link>
          <div style={{ minWidth: 0 }}>
            <strong style={{ display: 'block' }}>{slug ? <Link href={`/prestataires/${slug}`}>{name}</Link> : name}</strong>
            <small style={{ color: 'var(--muted)' }}>
              {[detail, conversation.project ? `Projet « ${conversation.project.title} »` : null].filter(Boolean).join(' · ')}
            </small>
          </div>
        </div>
        {!conversation.counterpart.deleted ? (
          conversation.blocked_by_me ? (
            <button
              type="button"
              className="report-link"
              onClick={async () => {
                await api(`me/conversations/${conversation.uuid}/block`, { method: 'DELETE' });
                onChanged();
              }}
            >
              Débloquer
            </button>
          ) : (
            <button type="button" className="report-link" onClick={() => setBlockDialog(true)}>
              <Icon name="ban" size={14} /> Bloquer
            </button>
          )
        ) : null}
      </div>

      <div
        className="thread__messages"
        ref={scroller}
        onScroll={(event) => {
          const element = event.currentTarget;
          stickToBottom.current = element.scrollHeight - element.scrollTop - element.clientHeight < 80;
        }}
        aria-live="polite"
      >
        {error ? <p className="alert alert--error">{error}</p> : null}
        {messages === null && !error ? <div className="skeleton" style={{ height: 120 }} /> : null}
        {olderPage ? (
          <button type="button" className="btn btn--outline" style={{ alignSelf: 'center' }} onClick={() => void loadOlder()}>
            Messages précédents
          </button>
        ) : null}
        {messages?.length === 0 ? (
          <p style={{ color: 'var(--muted)', textAlign: 'center', margin: 'auto' }}>La demande a été acceptée : écrivez votre premier message.</p>
        ) : null}
        {messages?.map((message, index) => {
          const previous = messages[index - 1];
          const newDay = !previous || new Date(previous.sent_at).toDateString() !== new Date(message.sent_at).toDateString();
          return (
            <div key={message.uuid} style={{ display: 'contents' }}>
              {newDay ? <span className="day-sep">{formatDate(message.sent_at, { weekday: 'long', day: 'numeric', month: 'long' })}</span> : null}
              <div className={`bubble${message.mine ? ' bubble--mine' : ''}`}>
                {message.body === null ? (
                  <span className="bubble__text bubble__text--hidden">Message masqué par la modération</span>
                ) : (
                  <span className="bubble__text">{message.body}</span>
                )}
                <span className="bubble__meta">
                  {formatTime(message.sent_at)}
                  {!message.mine && message.body !== null && !conversation.counterpart.deleted ? (
                    <button type="button" className="report-link" onClick={() => setReporting(message)}>
                      Signaler
                    </button>
                  ) : null}
                </span>
              </div>
            </div>
          );
        })}
      </div>

      {conversation.can_send ? (
        <form
          className="thread__composer"
          onSubmit={(event) => {
            event.preventDefault();
            void send();
          }}
        >
          <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 4 }}>
            <label htmlFor="composer" className="visually-hidden">
              Votre message
            </label>
            <textarea
              id="composer"
              className="textarea"
              rows={2}
              maxLength={MAX_LENGTH}
              placeholder="Écrivez votre message… (Entrée pour envoyer, Maj+Entrée pour aller à la ligne)"
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                  event.preventDefault();
                  void send();
                }
              }}
            />
            {sendError ? <span className="field__error">{sendError}</span> : null}
          </div>
          <button type="submit" className="btn btn--dark" disabled={sending || !draft.trim()} aria-label="Envoyer">
            <Icon name="send" size={18} />
          </button>
        </form>
      ) : (
        <p className="thread__notice">
          {conversation.counterpart.deleted
            ? 'Ce compte a été supprimé : la conversation est en lecture seule.'
            : conversation.blocked_by_me
              ? 'Vous avez bloqué cette personne. Débloquez-la pour lui écrire à nouveau.'
              : 'Vous ne pouvez plus écrire dans cette conversation.'}
        </p>
      )}
      <p className="disclaimer" style={{ margin: 0, padding: '0 24px 14px' }}>
        Ne communiquez ni coordonnées bancaires ni mots de passe. Loopartner ne vous demandera jamais de payer en dehors d&apos;un devis accepté.
      </p>

      {reporting ? (
        <PrivateReportDialog
          path={`me/conversations/${conversation.uuid}/messages/${reporting.uuid}/report`}
          subject="ce message"
          onClose={() => setReporting(null)}
        />
      ) : null}
      {blockDialog ? (
        <ConfirmDialog
          title={`Bloquer ${name} ?`}
          confirmLabel="Bloquer"
          onClose={() => setBlockDialog(false)}
          onConfirm={async () => {
            await api(`me/conversations/${conversation.uuid}/block`, { method: 'POST' });
            onChanged();
          }}
        >
          <p>Vous ne pourrez plus vous écrire ni vous envoyer de nouvelle demande. L&apos;historique reste lisible et vous pourrez débloquer plus tard.</p>
        </ConfirmDialog>
      ) : null}
    </section>
  );
}
