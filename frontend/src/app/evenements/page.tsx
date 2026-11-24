import type { Metadata } from 'next';
import { EventRow } from '@/components/EventRow';
import { Icon } from '@/components/Icon';
import { Highlight, PageShell } from '@/components/PageShell';
import { Pagination } from '@/components/Pagination';
import { apiOrNull } from '@/lib/api';
import type { Category, Paginated, PublicEvent } from '@/lib/types';

export const dynamic = 'force-dynamic';
export const metadata: Metadata = { title: 'Nos événements' };

type Search = { page?: string; q?: string; categorie?: string; langue?: string; date?: string };

export default async function EventsPage({ searchParams }: { searchParams: Promise<Search> }) {
  const { page: rawPage, q, categorie, langue, date } = await searchParams;
  const page = Math.max(1, Number.parseInt(rawPage ?? '1', 10) || 1);
  const query = q && q.trim().length >= 2 ? q.trim().slice(0, 100) : undefined;
  const from = date && /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : undefined;

  const [events, categories] = await Promise.all([
    apiOrNull<Paginated<PublicEvent>>('events', {
      query: { page, per_page: 10, q: query, category: categorie, language: langue === 'fr' || langue === 'en' ? langue : undefined, from },
    }),
    apiOrNull<{ data: Category[] }>('categories', { revalidate: 600 }),
  ]);

  const href = (target: number) =>
    `/evenements?${new URLSearchParams({
      ...(query ? { q: query } : {}),
      ...(categorie ? { categorie } : {}),
      ...(langue ? { langue } : {}),
      ...(from ? { date: from } : {}),
      page: String(target),
    })}`;

  return (
    <PageShell
      title={
        <>
          Nos <Highlight>événements</Highlight>
        </>
      }
      subtitle="Webinars animés par des professionnels pour bien lancer votre entreprise."
      bandExtra={
        <form className="toolbar" role="search" style={{ marginTop: 40 }}>
          <div className="search">
            <label htmlFor="event-q" className="visually-hidden">
              Rechercher un webinar
            </label>
            <input id="event-q" name="q" className="input" placeholder="Rechercher un webinar" defaultValue={query} minLength={2} maxLength={100} />
            <Icon name="search" size={18} />
          </div>
          <label htmlFor="event-category" className="visually-hidden">
            Catégorie
          </label>
          <select id="event-category" name="categorie" className="select select--pill" defaultValue={categorie ?? ''}>
            <option value="">Catégorie</option>
            {(categories?.data ?? []).map((category) => (
              <option key={category.slug} value={category.slug}>
                {category.name}
              </option>
            ))}
          </select>
          <label htmlFor="event-language" className="visually-hidden">
            Langue
          </label>
          <select id="event-language" name="langue" className="select select--pill" defaultValue={langue ?? ''}>
            <option value="">Langue</option>
            <option value="fr">Français</option>
            <option value="en">Anglais</option>
          </select>
          <label htmlFor="event-date" className="visually-hidden">
            À partir du
          </label>
          <input id="event-date" name="date" type="date" className="input select--pill" defaultValue={from} style={{ width: 'auto' }} />
          <button type="submit" className="btn btn--dark">
            Filtrer
          </button>
        </form>
      }
    >
      <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
        {events === null ? (
          <div className="card empty">L&apos;agenda ne peut pas être chargé pour le moment.</div>
        ) : events.data.length === 0 ? (
          <div className="card empty">Aucun événement à venir pour ces critères.</div>
        ) : (
          events.data.map((event) => <EventRow key={event.slug} event={event} />)
        )}
      </div>
      {events ? <Pagination current={events.meta.current_page} last={events.meta.last_page} href={href} /> : null}
    </PageShell>
  );
}
