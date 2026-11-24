'use client';

import Link from 'next/link';
import { useEffect, useMemo, useState } from 'react';
import { Icon } from '@/components/Icon';
import {
  AGENDA_SOURCES,
  type AgendaItem,
  type AgendaResponse,
  dayKey,
  itemDay,
  itemHref,
  kindLabel,
  SOURCE_ICONS,
  SOURCE_LABELS,
} from '@/lib/agenda';
import { api, ApiError } from '@/lib/api';
import { formatDate, formatTime } from '@/lib/format';
import { useSession } from '@/lib/session';

const WEEKDAYS = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];

interface Month {
  year: number;
  month: number; // 0 à 11
}

/** Journée (AAAA-MM-JJ) d'une date UTC construite à partir d'une journée du calendrier. */
function key(date: Date): string {
  return date.toISOString().slice(0, 10);
}

/** Cases de la grille du mois (semaines complètes, du lundi au dimanche). */
function monthGrid({ year, month }: Month): Date[] {
  const first = new Date(Date.UTC(year, month, 1));
  const offset = (first.getUTCDay() + 6) % 7;
  const days = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
  const cells = Math.ceil((offset + days) / 7) * 7;
  return Array.from({ length: cells }, (_, index) => new Date(Date.UTC(year, month, 1 - offset + index)));
}

function shift({ year, month }: Month, delta: number): Month {
  const date = new Date(Date.UTC(year, month + delta, 1));
  return { year: date.getUTCFullYear(), month: date.getUTCMonth() };
}

/**
 * Agenda complet (`GET /me/agenda`) : vue du mois, détail de la journée choisie, filtres par
 * source. L'abonnement depuis un logiciel de calendrier (`/me/agenda/feed`) n'est pas encore
 * proposé : la fonctionnalité n'est pas sortie.
 */
export function AgendaView() {
  const { user, loading } = useSession();
  const today = dayKey(new Date());
  const [current, setCurrent] = useState<Month>(() => ({ year: Number(today.slice(0, 4)), month: Number(today.slice(5, 7)) - 1 }));
  const [selected, setSelected] = useState(today);
  const [hidden, setHidden] = useState<string[]>([]);
  const [response, setResponse] = useState<AgendaResponse | null>(null);
  const [failed, setFailed] = useState<string | null>(null);

  const grid = useMemo(() => monthGrid(current), [current]);
  const from = key(grid[0]);
  const to = key(grid[grid.length - 1]);

  useEffect(() => {
    if (!user?.email_verified) return;
    let active = true;
    // Un jour de marge de chaque côté : l'API compte en journées UTC, l'affichage en heure de Paris.
    const before = key(new Date(Date.parse(from) - 86_400_000));
    const after = key(new Date(Date.parse(to) + 86_400_000));
    api<AgendaResponse>('me/agenda', { query: { from: before, to: after } })
      .then((result) => {
        if (!active) return;
        setResponse(result);
        setFailed(null);
      })
      .catch((exception: unknown) => active && setFailed(exception instanceof ApiError ? exception.message : 'L’agenda ne peut pas être chargé pour le moment.'));
    return () => {
      active = false;
    };
  }, [user?.email_verified, from, to]);

  const items = useMemo(() => (response?.data ?? []).filter((item) => !hidden.includes(item.source)), [response, hidden]);
  const byDay = useMemo(() => {
    const map = new Map<string, AgendaItem[]>();
    for (const item of items) {
      const day = itemDay(item);
      map.set(day, [...(map.get(day) ?? []), item]);
    }
    return map;
  }, [items]);

  if (loading) return <div className="skeleton" style={{ height: 420 }} />;
  if (!user) {
    return (
      <div className="card empty">
        <p>Connectez-vous pour voir votre agenda.</p>
        <Link href="/connexion?next=/agenda" className="btn btn--lime">
          Se connecter
        </Link>
      </div>
    );
  }
  if (!user.email_verified) {
    return <p className="alert alert--info">Vérifiez votre adresse email pour accéder à votre agenda. Le lien se renvoie depuis Mon espace.</p>;
  }

  const monthLabel = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(current.year, current.month, 1)));
  const inMonth = (date: Date) => date.getUTCMonth() === current.month;
  const dayItems = byDay.get(selected) ?? [];
  const upcoming = items.filter((item) => itemDay(item) >= today).slice(0, 8);
  const disclaimers = Object.entries(response?.disclaimers ?? {}).filter(([source]) => !hidden.includes(source));

  function goTo(month: Month) {
    setCurrent(month);
    setResponse(null);
    const first = key(new Date(Date.UTC(month.year, month.month, 1)));
    setSelected(today.slice(0, 7) === first.slice(0, 7) ? today : first);
  }

  return (
    <div className="form-stack" style={{ gap: 28 }}>
      <div className="chips" role="group" aria-label="Afficher">
        {AGENDA_SOURCES.map((source) => {
          const shown = !hidden.includes(source);
          return (
            <button
              key={source}
              type="button"
              className="chip"
              aria-pressed={shown}
              onClick={() => setHidden((list) => (shown ? [...list, source] : list.filter((entry) => entry !== source)))}
            >
              <span className={`agenda-dot agenda-dot--${source}`} aria-hidden="true" /> {SOURCE_LABELS[source]}
            </button>
          );
        })}
      </div>

      {failed ? <p className="alert alert--error">{failed}</p> : null}

      <div className="with-aside with-aside--right">
        <section className="card agenda-month" aria-label={`Agenda de ${monthLabel}`}>
          <div className="agenda-month__head">
            <button type="button" className="btn btn--outline btn--sm" onClick={() => goTo(shift(current, -1))} aria-label="Mois précédent">
              <Icon name="arrowLeft" size={16} />
            </button>
            <h2>{monthLabel}</h2>
            <button type="button" className="btn btn--outline btn--sm" onClick={() => goTo(shift(current, 1))} aria-label="Mois suivant">
              <Icon name="arrowRight" size={16} />
            </button>
            <button
              type="button"
              className="btn btn--ghost btn--sm"
              onClick={() => {
                goTo({ year: Number(today.slice(0, 4)), month: Number(today.slice(5, 7)) - 1 });
                setSelected(today);
              }}
            >
              Aujourd&apos;hui
            </button>
          </div>
          <div className="agenda-grid" role="grid">
            {WEEKDAYS.map((weekday) => (
              <span key={weekday} className="agenda-grid__weekday" role="columnheader">
                {weekday}
              </span>
            ))}
            {grid.map((date) => {
              const day = key(date);
              const entries = byDay.get(day) ?? [];
              const classes = ['agenda-grid__day'];
              if (!inMonth(date)) classes.push('is-outside');
              if (day === today) classes.push('is-today');
              if (day === selected) classes.push('is-selected');
              return (
                <button
                  key={day}
                  type="button"
                  role="gridcell"
                  className={classes.join(' ')}
                  aria-selected={day === selected}
                  aria-label={`${formatDate(`${day}T12:00:00Z`, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })}, ${entries.length} élément${entries.length > 1 ? 's' : ''}`}
                  onClick={() => setSelected(day)}
                >
                  <span className="agenda-grid__number">{date.getUTCDate()}</span>
                  <span className="agenda-grid__entries">
                    {entries.slice(0, 2).map((item) => (
                      <span key={`${item.source}:${item.ref}`} className={`agenda-grid__entry agenda-grid__entry--${item.source}`}>
                        {item.title}
                      </span>
                    ))}
                    {entries.length > 2 ? <span className="agenda-grid__more">+{entries.length - 2}</span> : null}
                  </span>
                  <span className="agenda-grid__dots" aria-hidden="true">
                    {entries.slice(0, 3).map((item) => (
                      <span key={`${item.source}:${item.ref}`} className={`agenda-dot agenda-dot--${item.source}`} />
                    ))}
                  </span>
                </button>
              );
            })}
          </div>
          {response === null && !failed ? <p className="agenda-month__loading">Chargement…</p> : null}
        </section>

        <aside className="aside-stack">
          <div className="card aside-card">
            <h3 style={{ textTransform: 'capitalize' }}>{formatDate(`${selected}T12:00:00Z`, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })}</h3>
            {dayItems.length === 0 ? <p className="empty" style={{ padding: 0 }}>Rien de prévu ce jour-là.</p> : <AgendaEntries items={dayItems} />}
          </div>
          <div className="card aside-card">
            <h3>Prochainement</h3>
            {upcoming.length === 0 ? <p className="empty" style={{ padding: 0 }}>Rien de prévu pour la suite de ce mois.</p> : <AgendaEntries items={upcoming} withDate />}
          </div>
        </aside>
      </div>

      {disclaimers.map(([source, disclaimer]) => (
        <p key={source} className="alert alert--info">
          <strong>{SOURCE_LABELS[source] ?? source} : </strong>
          {disclaimer.text}
        </p>
      ))}

    </div>
  );
}

function AgendaEntries({ items, withDate = false }: { items: AgendaItem[]; withDate?: boolean }) {
  return (
    <ul className="agenda-entries">
      {items.map((item) => {
        const href = itemHref(item);
        const when = item.all_day ? 'Toute la journée' : `${formatTime(item.starts_at)} – ${formatTime(item.ends_at)}`;
        const content = (
          <>
            <span className={`agenda-dot agenda-dot--${item.source}`} aria-hidden="true" />
            <span style={{ flex: 1, minWidth: 0 }}>
              <strong>{item.title}</strong>
              <small>
                <Icon name={SOURCE_ICONS[item.source] ?? 'calendar'} size={13} /> {kindLabel(item)} ·{' '}
                {withDate ? `${formatDate(item.all_day && item.date ? `${item.date}T12:00:00Z` : item.starts_at, { day: 'numeric', month: 'short', ...(item.all_day ? { timeZone: 'UTC' } : {}) })}, ` : ''}
                {when}
              </small>
            </span>
          </>
        );
        return <li key={`${item.source}:${item.ref}`}>{href ? <Link href={href}>{content}</Link> : <div>{content}</div>}</li>;
      })}
    </ul>
  );
}
