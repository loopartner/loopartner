import type { Metadata } from 'next';
import Link from 'next/link';
import { notFound } from 'next/navigation';
import { eventAvailability } from '@/components/EventRow';
import { Icon } from '@/components/Icon';
import { PageShell } from '@/components/PageShell';
import { apiOrNull } from '@/lib/api';
import { dateBadge, duration, eventFormatName, formatDate, formatTime, initials, languageName } from '@/lib/format';
import type { Paginated, PublicEvent } from '@/lib/types';
import { RegisterButton } from './RegisterButton';

export const dynamic = 'force-dynamic';

const SLUG = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

async function load(slug: string): Promise<PublicEvent | null> {
  if (!SLUG.test(slug)) return null;
  const response = await apiOrNull<{ data: PublicEvent }>(`events/${slug}`);
  return response?.data ?? null;
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const event = await load((await params).slug);
  return { title: event?.title ?? 'Événement' };
}

export default async function EventPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const event = await load(slug);
  if (event === null) notFound();

  const others = await apiOrNull<Paginated<PublicEvent>>('events', { query: { per_page: 4 } });
  const more = (others?.data ?? []).filter((item) => item.slug !== event.slug).slice(0, 3);
  const badge = dateBadge(event.starts_at, event.timezone);

  return (
    <PageShell
      eyebrow={
        <>
          {event.categories[0] ? <span className="pill pill--soft">{event.categories[0].name}</span> : null}
          {event.status === 'past' ? <span className="pill pill--soft">Terminé</span> : null}
        </>
      }
      title={event.title}
    >
      <div className="with-aside with-aside--right">
        <article className="card" style={{ padding: 40 }}>
          <div className="project-card__pills" style={{ marginBottom: 24 }}>
            <span className="pill pill--budget">
              <Icon name="video" size={14} /> {eventFormatName(event.format)}
            </span>
            <span className="pill pill--tag">
              <Icon name="clock" size={14} /> {duration(event.starts_at, event.ends_at)}
            </span>
            <span className="pill pill--tag">
              <Icon name="globe" size={14} /> {languageName(event.language)}
            </span>
          </div>
          {event.cover_url ? (
            // eslint-disable-next-line @next/next/no-img-element
            <img src={event.cover_url} alt="" style={{ width: '100%', borderRadius: 24, aspectRatio: '16 / 9', objectFit: 'cover', marginBottom: 32 }} />
          ) : null}
          <h2 style={{ fontSize: 20, margin: '0 0 12px' }}>Au programme</h2>
          <div className="prose">
            <p>{event.description}</p>
          </div>
          {event.venue ? (
            <p className="note" style={{ marginTop: 24 }}>
              <span className="note__icon">
                <Icon name="globe" size={16} />
              </span>
              {[event.venue.name, event.venue.address_line, event.venue.postal_code, event.venue.city].filter(Boolean).join(', ') ||
                'Adresse communiquée aux inscrits.'}
            </p>
          ) : null}
          <p className="disclaimer" style={{ textAlign: 'left', borderTop: '1px solid var(--border)', paddingTop: 24, marginTop: 32 }}>
            Cet événement est informatif et ne remplace pas un conseil personnalisé.
          </p>
        </article>

        <aside className="aside-stack">
          <div className="card">
            <h2 style={{ fontSize: 18, margin: '0 0 20px' }}>Informations</h2>
            <div className="author" style={{ gap: 16, marginBottom: 8 }}>
              <span className="date-badge date-badge--sm">
                <strong>{badge.day}</strong>
                <span>{badge.month}</span>
              </span>
              <span>
                <strong style={{ fontSize: 18 }}>{formatDate(event.starts_at, { day: 'numeric', month: 'short', year: 'numeric', timeZone: event.timezone })}</strong>
                <br />
                <small style={{ color: 'var(--muted)' }}>
                  {formatTime(event.starts_at, event.timezone)} ({event.timezone === 'Europe/Paris' ? 'heure de Paris' : event.timezone})
                </small>
              </span>
            </div>
            <dl className="info-list">
              <div>
                <dt>Durée</dt>
                <dd>{duration(event.starts_at, event.ends_at)}</dd>
              </div>
              <div>
                <dt>Langue</dt>
                <dd>{languageName(event.language)}</dd>
              </div>
              <div>
                <dt>Disponibilité</dt>
                <dd style={{ color: 'var(--blue)' }}>{eventAvailability(event)}</dd>
              </div>
            </dl>
            <div style={{ marginTop: 24 }}>
              <RegisterButton event={event} />
            </div>
          </div>

          <div className="card" style={{ textAlign: 'center' }}>
            <h2 style={{ fontSize: 18, margin: '0 0 20px', textAlign: 'left' }}>Animé par</h2>
            {event.organizer.logo_url ? (
              // eslint-disable-next-line @next/next/no-img-element
              <img src={event.organizer.logo_url} alt="" className="avatar avatar--lg" style={{ margin: '0 auto 12px' }} />
            ) : (
              <span className="avatar avatar--lg" style={{ margin: '0 auto 12px' }}>
                {initials(event.organizer.name)}
              </span>
            )}
            <strong style={{ display: 'block' }}>{event.organizer.name}</strong>
            {event.organizer.headline ? <small style={{ color: 'var(--muted)' }}>{event.organizer.headline}</small> : null}
            {event.organizer.kind === 'provider' && event.organizer.name ? (
              <Link href={`/prestataires?q=${encodeURIComponent(event.organizer.name)}`} className="btn btn--outline btn--block" style={{ marginTop: 20 }}>
                Voir le profil
              </Link>
            ) : null}
          </div>
        </aside>
      </div>

      {more.length > 0 ? (
        <section style={{ marginTop: 64 }} aria-labelledby="more-events">
          <h2 id="more-events" className="section-title">
            Autres événements
          </h2>
          <div className="card-grid card-grid--3">
            {more.map((item) => {
              const itemBadge = dateBadge(item.starts_at, item.timezone);
              return (
                <Link key={item.slug} href={`/evenements/${item.slug}`} className="card" style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
                  <span className="author" style={{ alignItems: 'flex-start', gap: 14 }}>
                    <span className="date-badge date-badge--sm">
                      <strong>{itemBadge.day}</strong>
                      <span>{itemBadge.month}</span>
                    </span>
                    <strong style={{ fontFamily: 'var(--font-text)' }}>{item.title}</strong>
                  </span>
                  <span style={{ fontFamily: 'var(--font-text)', fontSize: 12, color: 'var(--muted)', display: 'flex', gap: 16 }}>
                    <span>
                      <Icon name="clock" size={12} /> {duration(item.starts_at, item.ends_at)}
                    </span>
                    <span style={{ color: 'var(--blue)' }}>
                      <Icon name="video" size={12} /> {eventFormatName(item.format)}
                    </span>
                  </span>
                </Link>
              );
            })}
          </div>
        </section>
      ) : null}
    </PageShell>
  );
}
