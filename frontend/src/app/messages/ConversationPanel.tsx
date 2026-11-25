'use client';

import Link from 'next/link';
import { Icon } from '@/components/Icon';
import { counterpartDetail, counterpartName } from '@/lib/counterpart';
import { formatDate, formatTime } from '@/lib/format';
import { formatCents, type Quote, STATUS_CLASS, STATUS_LABELS } from '@/lib/quotes';
import type { ChatMessage, Conversation } from '@/lib/types';
import { ChatAvatar } from './ChatAvatar';

/**
 * Panneau d'une conversation : l'interlocuteur (seulement ce que l'API montre déjà : nom une fois
 * la demande acceptée, type de compte, pays, profil public d'un prestataire), la mise en relation
 * (depuis quand, dernier message reçu, projet) et les documents échangés (devis).
 * Aucune présence en ligne : l'API n'en conserve pas.
 */
export function ConversationPanel({
  conversation,
  messages,
  quotes,
  newQuoteHref,
}: {
  conversation: Conversation;
  messages: ChatMessage[] | null;
  quotes: Quote[] | null;
  newQuoteHref: string | null;
}) {
  const name = counterpartName(conversation.counterpart);
  const provider = conversation.counterpart.provider;
  const lastReceived = messages ? [...messages].reverse().find((message) => !message.mine) : undefined;

  return (
    <aside className="card conv-panel" aria-label="Informations sur la conversation">
      <div className="conv-panel__who">
        <ChatAvatar name={name} logo={provider?.logo_url ?? null} size="lg" />
        <strong className="conv-panel__name">{name}</strong>
        {/* Prestataire : son accroche est montrée juste dessous, on donne ici sa ville. */}
        <small className="conv-panel__detail">
          {provider ? [provider.city, provider.country === 'FR' ? 'France' : provider.country].filter(Boolean).join(', ') : counterpartDetail(conversation.counterpart)}
        </small>
        {provider?.headline ? <p className="conv-panel__headline">{provider.headline}</p> : null}
        {provider?.slug ? (
          <Link href={`/prestataires/${provider.slug}`} className="btn btn--outline btn--sm">
            Voir le profil
          </Link>
        ) : null}
      </div>

      <dl className="conv-panel__facts">
        <div>
          <dt>En relation depuis</dt>
          <dd>{formatDate(conversation.created_at, { day: 'numeric', month: 'long', year: 'numeric' })}</dd>
        </div>
        <div>
          <dt>Dernier message reçu</dt>
          <dd>
            {lastReceived
              ? `${formatDate(lastReceived.sent_at, { day: 'numeric', month: 'long' })} à ${formatTime(lastReceived.sent_at)}`
              : messages === null
                ? '…'
                : 'Pas encore de message'}
          </dd>
        </div>
        {conversation.project ? (
          <div>
            <dt>Projet</dt>
            <dd>{conversation.project.title}</dd>
          </div>
        ) : null}
      </dl>

      <div className="conv-panel__docs">
        <div className="conv-panel__docs-head">
          <h3>Documents</h3>
          {quotes && quotes.length > 0 ? <span className="inbox__count">{quotes.length}</span> : null}
        </div>
        {quotes === null ? <div className="skeleton" style={{ height: 56 }} /> : null}
        {quotes?.length === 0 ? <p className="conv-panel__empty">Aucun devis échangé pour l&apos;instant.</p> : null}
        {quotes && quotes.length > 0 ? (
          <ul className="conv-panel__list">
            {quotes.map((quote) => (
              <li key={quote.uuid}>
                <Link href={`/devis/${quote.uuid}?conv=${conversation.uuid}`} className="conv-doc">
                  <span className="conv-doc__icon" aria-hidden="true">
                    <Icon name="file" size={18} />
                  </span>
                  <span className="conv-doc__body">
                    <strong>{quote.title}</strong>
                    <small>
                      {quote.number ?? 'Brouillon'} · {formatCents(quote.totals?.ttc_cents, quote.currency)} TTC
                    </small>
                  </span>
                  <span className={`status ${STATUS_CLASS[quote.status]}`}>{STATUS_LABELS[quote.status]}</span>
                </Link>
              </li>
            ))}
          </ul>
        ) : null}
        {newQuoteHref ? (
          <Link href={newQuoteHref} className="btn btn--lime btn--sm btn--block">
            <Icon name="plus" size={16} /> Faire un devis
          </Link>
        ) : null}
      </div>
    </aside>
  );
}
