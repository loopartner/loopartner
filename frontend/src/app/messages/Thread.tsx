'use client';

import Link from 'next/link';
import { useCallback, useEffect, useRef, useState } from 'react';
import { ConfirmDialog } from '@/components/ConfirmDialog';
import { Icon } from '@/components/Icon';
import { PrivateReportDialog } from '@/components/PrivateReportDialog';
import { api, ApiError } from '@/lib/api';
import { counterpartDetail, counterpartName } from '@/lib/counterpart';
import { formatDate, formatTime } from '@/lib/format';
import { formatCents, type Quote, STATUS_CLASS, STATUS_LABELS } from '@/lib/quotes';
import type { ChatMessage, Conversation, MatchRequest, Paginated } from '@/lib/types';
import { ChatAvatar } from './ChatAvatar';
import { ConversationPanel } from './ConversationPanel';

const POLL_MS = 10_000;
const QUOTES_POLL_MS = 30_000;
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
  const [origin, setOrigin] = useState<MatchRequest | null>(null);
  const [quotes, setQuotes] = useState<Quote[] | null>(null);
  const [panelOpen, setPanelOpen] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);
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

  // Proposition ou demande de départ, affichée en tête du fil.
  const requestUuid = conversation.match_request?.uuid;
  useEffect(() => {
    if (!requestUuid) return;
    let active = true;
    api<{ data: MatchRequest }>(`me/match-requests/${requestUuid}`)
      .then((response) => active && setOrigin(response.data))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [requestUuid]);

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

  const matchRequestUuid = conversation.match_request?.uuid ?? null;
  // Suis-je le prestataire de cette mise en relation ? Une proposition vient du prestataire, une
  // demande de contact lui est adressée. Avant de connaître la demande (ou si elle n'est plus
  // lisible), l'interlocuteur sans profil prestataire est le porteur de projet.
  const iAmProvider = origin
    ? origin.type === 'proposal'
      ? origin.role === 'author'
      : origin.role === 'recipient'
    : !conversation.counterpart.deleted && conversation.counterpart.provider === null;
  const quotesBox = iAmProvider ? 'sent' : 'received';
  useEffect(() => {
    if (!matchRequestUuid) return;
    let active = true;
    const load = () =>
      api<Paginated<Quote>>('me/quotes', { query: { box: quotesBox, match_request: matchRequestUuid, per_page: 50 } })
        .then((response) => active && setQuotes(response.data))
        .catch(() => active && setQuotes((current) => current ?? []));
    void load();
    // Un devis envoyé pendant que la conversation est ouverte apparaît sans recharger la page.
    const timer = window.setInterval(() => document.visibilityState === 'visible' && void load(), QUOTES_POLL_MS);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [matchRequestUuid, quotesBox]);

  const name = counterpartName(conversation.counterpart);
  const detail = counterpartDetail(conversation.counterpart);
  const newQuoteHref =
    iAmProvider && conversation.match_request && conversation.can_send
      ? `/devis/nouveau?demande=${conversation.match_request.uuid}${conversation.counterpart.name ? `&nom=${encodeURIComponent(conversation.counterpart.name)}` : ''}&conv=${conversation.uuid}`
      : null;
  // Devis envoyés (ou reçus) dans cette conversation, montrés dans le fil à leur date d'envoi.
  const conversationQuotes = matchRequestUuid ? quotes : [];
  const sentQuotes = (conversationQuotes ?? []).filter((quote) => quote.sent_at).sort((a, b) => (a.sent_at as string).localeCompare(b.sent_at as string));
  // Fil unique : messages et devis envoyés, dans l'ordre chronologique. Tant que des messages plus
  // anciens restent à charger, les devis plus anciens que le premier message affiché attendent.
  const firstShown = messages?.[0]?.sent_at ?? null;
  const timeline: Array<{ kind: 'message'; at: string; message: ChatMessage } | { kind: 'quote'; at: string; quote: Quote }> = messages
    ? [
        ...messages.map((message) => ({ kind: 'message' as const, at: message.sent_at, message })),
        ...sentQuotes
          .filter((quote) => !olderPage || !firstShown || (quote.sent_at as string) >= firstShown)
          .map((quote) => ({ kind: 'quote' as const, at: quote.sent_at as string, quote })),
      ].sort((a, b) => a.at.localeCompare(b.at))
    : [];
  const slug = conversation.counterpart.provider?.slug;
  const logo = conversation.counterpart.provider?.logo_url;

  return (
    <div className={`thread-layout${panelOpen ? ' thread-layout--panel' : ''}`}>
    <section className="card thread" aria-label={`Conversation avec ${name}`}>
      <div className="thread__head">
        <div style={{ display: 'flex', alignItems: 'center', gap: 12, minWidth: 0 }}>
          <Link href="/messages" className="thread__back btn btn--ghost" aria-label="Retour aux conversations" style={{ padding: 6 }}>
            <Icon name="arrowLeft" />
          </Link>
          <ChatAvatar name={name} logo={logo} size="lg" />
          <div style={{ minWidth: 0 }}>
            <strong className="thread__name">{slug ? <Link href={`/prestataires/${slug}`}>{name}</Link> : name}</strong>
            <small className="thread__detail">{detail}</small>
            {conversation.project ? (
              <span className="thread__project">
                <Icon name="briefcase" size={13} /> {conversation.project.title}
              </span>
            ) : null}
          </div>
        </div>
        <div className="thread__actions">
        {/* Le prestataire rédige un devis dans la mise en relation acceptée (décision 36). */}
        {newQuoteHref ? (
          <Link href={newQuoteHref} className="btn btn--lime btn--sm thread__quote-btn">
            <Icon name="file" size={16} /> Faire un devis
          </Link>
        ) : null}
        <button type="button" className="btn btn--outline btn--sm thread__panel-toggle" aria-expanded={panelOpen} onClick={() => setPanelOpen(!panelOpen)}>
          <Icon name="info" size={16} /> Infos
        </button>
        {!conversation.counterpart.deleted ? (
          conversation.blocked_by_me ? (
            <button
              type="button"
              className="report-link"
              onClick={async () => {
                setActionError(null);
                try {
                  await api(`me/conversations/${conversation.uuid}/block`, { method: 'DELETE' });
                  onChanged();
                } catch (exception) {
                  setActionError(exception instanceof ApiError ? exception.message : 'Déblocage impossible.');
                }
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
        {actionError ? <p className="alert alert--error">{actionError}</p> : null}
        {messages === null && !error ? <div className="skeleton" style={{ height: 120 }} /> : null}
        {olderPage ? (
          <button type="button" className="btn btn--outline" style={{ alignSelf: 'center' }} onClick={() => void loadOlder()}>
            Messages précédents
          </button>
        ) : null}
        {origin && !olderPage ? (
          <div className={`bubble bubble--origin${origin.role === 'author' ? ' bubble--mine' : ''}`}>
            <span className="bubble__label">
              <Icon name={origin.type === 'proposal' ? 'briefcase' : 'message'} size={14} />{' '}{origin.type === 'proposal' ? (origin.role === 'author' ? 'Votre proposition' : 'Proposition') : origin.role === 'author' ? 'Votre demande de contact' : 'Demande de contact'}</span>
            <span className={`bubble__text${origin.message === null ? ' bubble__text--hidden' : ''}`}>{origin.message ?? 'Message masqué par la modération'}</span>
            {origin.price ? <span className="bubble__price">Prix proposé · {origin.price.amount.toLocaleString('fr-FR')} {origin.price.currency === 'EUR' ? '€' : origin.price.currency}</span> : null}
            <span className="bubble__meta">{formatDate(origin.created_at, { day: 'numeric', month: 'long' })}</span>
          </div>
        ) : null}
        {messages?.length === 0 ? (
          <p style={{ color: 'var(--muted)', textAlign: 'center', margin: 'auto' }}>La demande a été acceptée : écrivez votre premier message.</p>
        ) : null}
        {timeline.map((item, index) => {
          const previous = timeline[index - 1];
          const next = timeline[index + 1];
          const newDay = !previous || new Date(previous.at).toDateString() !== new Date(item.at).toDateString();
          const separator = newDay ? <span className="day-sep">{formatDate(item.at, { weekday: 'long', day: 'numeric', month: 'long' })}</span> : null;
          if (item.kind === 'quote') {
            return (
              <div key={item.quote.uuid} style={{ display: 'contents' }}>
                {separator}
                <QuoteBubble quote={item.quote} conversation={conversation.uuid} />
              </div>
            );
          }
          const message = item.message;
          // Avatar sous le dernier message d'une suite de l'interlocuteur (même jour, sans devis entre deux).
          const lastOfGroup =
            !next || next.kind !== 'message' || next.message.mine !== message.mine || new Date(next.at).toDateString() !== new Date(item.at).toDateString();
          return (
            <div key={message.uuid} style={{ display: 'contents' }}>
              {separator}
              <div className={`bubble-row${message.mine ? ' bubble-row--mine' : ''}`}>
                {!message.mine ? lastOfGroup ? <ChatAvatar name={name} logo={logo} size="sm" /> : <span className="chat-avatar chat-avatar--sm chat-avatar--spacer" /> : null}
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
              placeholder="Écrivez votre message…"
              title="Entrée pour envoyer, Maj+Entrée pour aller à la ligne"
              value={draft}
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={(event) => {
                // Entrée envoie au clavier ; sur un écran tactile, elle va à la ligne (bouton pour envoyer).
                if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing && !window.matchMedia('(pointer: coarse)').matches) {
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
    <ConversationPanel conversation={conversation} messages={messages} quotes={conversationQuotes} newQuoteHref={newQuoteHref} />
    </div>
  );
}

/** Devis montré dans le fil de la conversation, à sa date d'envoi. */
function QuoteBubble({ quote, conversation }: { quote: Quote; conversation: string }) {
  const mine = quote.viewer_role === 'provider';
  return (
    <div className={`bubble-row${mine ? ' bubble-row--mine' : ''}`}>
      {/* Aligné sur les messages reçus, qui laissent la place de l'avatar. */}
      {!mine ? <span className="chat-avatar chat-avatar--sm chat-avatar--spacer" /> : null}
      <Link href={`/devis/${quote.uuid}?conv=${conversation}`} className={`quote-bubble${mine ? ' quote-bubble--mine' : ''}`}>
        <span className="quote-bubble__label">
          <Icon name="file" size={14} /> {mine ? 'Vous avez envoyé un devis' : 'Devis reçu'}
        </span>
        <strong className="quote-bubble__title">{quote.title}</strong>
        <span className="quote-bubble__row">
          <span>{quote.number}</span>
          <strong>{formatCents(quote.totals?.ttc_cents, quote.currency)} TTC</strong>
        </span>
        <span className="quote-bubble__row">
          <span className={`status ${STATUS_CLASS[quote.status]}`}>{STATUS_LABELS[quote.status]}</span>
          <span className="quote-bubble__open">
            {mine ? 'Voir le devis' : quote.status === 'sent' ? 'Consulter et répondre' : 'Voir le devis'} <Icon name="arrowRight" size={14} />
          </span>
        </span>
        <span className="bubble__meta">{quote.sent_at ? formatTime(quote.sent_at) : ''}</span>
      </Link>
    </div>
  );
}
