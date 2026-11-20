import Link from 'next/link';
import { dateBadge, duration, eventFormatName, formatTime, initials, languageName } from '@/lib/format';
import type { PublicEvent } from '@/lib/types';
import { Icon } from './Icon';

function availability(event: PublicEvent): string {
  if (event.registration.mode === 'external') return 'Billetterie externe';
  if (!event.registration.is_open) return 'Inscriptions fermées';
  if (event.registration.is_full) return 'Complet';
  if (event.registration.spots_left !== null) return `${event.registration.spots_left} place${event.registration.spots_left > 1 ? 's' : ''} restante${event.registration.spots_left > 1 ? 's' : ''}`;
  return 'Places disponibles';
}

export function EventRow({ event }: { event: PublicEvent }) {
  const badge = dateBadge(event.starts_at, event.timezone);

  return (
    <article className="card ticket event-row">
      <span className="date-badge">
        <strong>{badge.day}</strong>
        <span>{badge.month}</span>
      </span>
      <div className="event-row__body">
        {event.categories[0] ? <span className="pill pill--category">{event.categories[0].name}</span> : null}
        <h2 className="event-row__title">
          <Link href={`/evenements/${event.slug}`}>{event.title}</Link>
        </h2>
        <p className="event-row__excerpt">{event.description}</p>
        <div className="event-row__meta">
          <span className="author">
            {event.organizer.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={event.organizer.logo_url} alt="" className="avatar" style={{ width: 24, height: 24 }} />
            ) : (
              <span className="avatar" style={{ width: 24, height: 24, fontSize: 10 }}>
                {initials(event.organizer.name)}
              </span>
            )}
            <strong>{event.organizer.name}</strong>
            {event.organizer.headline ? <span>· {event.organizer.headline}</span> : null}
          </span>
          <span>
            <Icon name="clock" size={14} /> {formatTime(event.starts_at, event.timezone)} ({duration(event.starts_at, event.ends_at)})
          </span>
          <span>
            <Icon name="video" size={14} /> {eventFormatName(event.format)}
          </span>
          <span className="pill pill--tag" style={{ padding: '2px 10px' }}>
            {languageName(event.language)}
          </span>
        </div>
      </div>
      <div className="event-row__cta">
        <Link href={`/evenements/${event.slug}`} className="btn btn--dark">
          {event.registration.mode === 'external' ? 'Voir' : "S'inscrire"}
        </Link>
        <span>{availability(event)}</span>
      </div>
    </article>
  );
}

export { availability as eventAvailability };
