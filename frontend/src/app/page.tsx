import Image from 'next/image';
import Link from 'next/link';
import { Icon, type IconName } from '@/components/Icon';
import { ProjectCard } from '@/components/ProjectCard';
import { SiteFooter } from '@/components/SiteFooter';
import { SiteHeader } from '@/components/SiteHeader';
import { apiOrNull } from '@/lib/api';
import { dateBadge } from '@/lib/format';
import type { Category, Paginated, PublicEvent, PublicProject } from '@/lib/types';
import { HomeSearch } from './HomeSearch';
import { RotatingWord } from './RotatingWord';

/** Métiers qui défilent dans le titre (« avec les bons … »). */
const HERO_WORDS = ['experts', 'juristes', 'avocats', 'comptables', 'fiscalistes', 'conseillers'];

export const dynamic = 'force-dynamic';

const WHY: Array<{ icon: IconName; title: string; text: string }> = [
  {
    icon: 'shield',
    title: 'Professionnels déclarés',
    text: "Les prestataires qui publient des offres et envoient des devis renseignent leur identité professionnelle (dénomination, SIREN, assurance) et s'engagent sur son exactitude.",
  },
  {
    icon: 'message',
    title: 'Messagerie sécurisée',
    text: 'Vos échanges restent sur Loopartner. Vos coordonnées ne sont jamais affichées publiquement et votre nom n’est révélé qu’après votre accord.',
  },
  {
    icon: 'gift',
    title: 'Devis gratuits',
    text: 'Publier un projet et recevoir des propositions ne vous coûte rien. Vous choisissez librement le professionnel qui vous convient.',
  },
  {
    icon: 'book',
    title: 'Ressources utiles',
    text: 'Articles, guides et webinars animés par des professionnels pour bien lancer et gérer votre entreprise.',
  },
];

export default async function HomePage() {
  const [projects, events, categories] = await Promise.all([
    apiOrNull<Paginated<PublicProject>>('public/projects', { query: { per_page: 4 } }),
    apiOrNull<Paginated<PublicEvent>>('events', { query: { per_page: 2 } }),
    apiOrNull<{ data: Category[] }>('categories', { revalidate: 600 }),
  ]);

  const chips = (categories?.data ?? []).slice(0, 6);

  return (
    <>
      <a href="#contenu" className="skip-link">
        Aller au contenu
      </a>
      <div className="hero-band">
        <SiteHeader />
        <HeroDecorations />
        <section className="container home-hero" aria-labelledby="hero-title">
          <span className="home-hero__badge">
            <span className="home-hero__avatars" aria-hidden="true">
              {[1, 2, 3, 4].map((index) => (
                <Image key={index} src={`/images/hero/avatar-${index}.webp`} alt="" width={32} height={32} />
              ))}
            </span>
            Gratuit et sans engagement
          </span>
          <h1 id="hero-title" className="home-hero__title">
            Lancez votre entreprise <br />
            avec les bons <RotatingWord words={HERO_WORDS} />
          </h1>
          <HomeSearch />
          {chips.length > 0 ? (
            <nav className="home-chips" aria-label="Catégories">
              {chips.map((category) => (
                <Link key={category.slug} href={`/publier?categorie=${category.slug}`}>
                  {category.name}
                </Link>
              ))}
            </nav>
          ) : null}
        </section>
      </div>

      <main id="contenu" className="sheet">
        <div className="container" style={{ display: 'flex', flexDirection: 'column', gap: 120 }}>
          <section aria-labelledby="recent-title">
            <h2 id="recent-title" className="section-title">
              Projets récemment publiés
              <Link href="/projets" style={{ fontSize: 14, fontWeight: 600 }}>
                Tous les projets
              </Link>
            </h2>
            {projects && projects.data.length > 0 ? (
              <div className="card-grid card-grid--2">
                {projects.data.map((project) => (
                  <ProjectCard key={project.uuid} project={project} compact />
                ))}
              </div>
            ) : (
              <div className="card empty">
                Aucun projet publié pour le moment. <Link href="/publier">Publiez le premier !</Link>
              </div>
            )}
            <div style={{ textAlign: 'center', marginTop: 32 }}>
              <Link href="/projets" className="btn btn--dark">
                Voir tous les projets
              </Link>
            </div>
          </section>

          <section className="why" aria-labelledby="why-title">
            <div>
              <h2 id="why-title" className="section-title">
                Pourquoi Loopartner ?
              </h2>
              <ul className="why__list">
                {WHY.map((item) => (
                  <li key={item.title}>
                    <Icon name={item.icon} size={22} />
                    <div>
                      <h3>{item.title}</h3>
                      <p>{item.text}</p>
                    </div>
                  </li>
                ))}
              </ul>
            </div>
            <div className="card" aria-hidden="true" style={{ transform: 'rotate(-2deg)' }}>
              <div className="project-card__pills" style={{ marginBottom: 16 }}>
                <span className="pill pill--status">Ouvert</span>
                <span className="pill pill--budget">500 – 1 000 €</span>
              </div>
              <h3 style={{ margin: '0 0 12px', fontSize: 20 }}>Détails de la mission</h3>
              <p style={{ fontFamily: 'var(--font-text)', fontSize: 14, color: 'var(--muted)', margin: '0 0 20px' }}>
                Décrivez votre besoin en quelques lignes : les prestataires vous répondent avec une proposition adaptée.
              </p>
              <div className="project-card__pills">
                <span className="pill pill--tag">Statuts</span>
                <span className="pill pill--tag">Greffe</span>
              </div>
            </div>
          </section>

          <section id="comment-ca-marche" aria-labelledby="how-title">
            <h2 id="how-title" className="section-title">
              Comment ça marche ?
            </h2>
            <div className="how">
              <div className="card">
                <span className="how__step">1</span>
                <h3>Publiez votre projet</h3>
                <p>Décrivez vos besoins en quelques clics. C’est simple, rapide et totalement gratuit.</p>
              </div>
              <div className="card">
                <span className="how__step">2</span>
                <h3>Recevez des propositions</h3>
                <p>Des prestataires étudient votre demande et vous envoient leurs propositions et leurs devis.</p>
              </div>
              <div className="card">
                <span className="how__step">3</span>
                <h3>Travaillez ensemble</h3>
                <p>Choisissez le professionnel qui vous convient, échangez sur la messagerie et comparez les devis reçus.</p>
              </div>
            </div>
          </section>

          <section className="events-teaser" aria-labelledby="events-title">
            <div>
              <h2 id="events-title">Prochains événements</h2>
              <p>Rejoignez des professionnels lors de sessions en direct pour poser vos questions et accélérer votre projet.</p>
              <Link href="/evenements" className="btn btn--dark">
                Voir l&apos;agenda
              </Link>
            </div>
            <div>
              {events && events.data.length > 0 ? (
                events.data.map((event) => {
                  const badge = dateBadge(event.starts_at, event.timezone);
                  return (
                    <Link key={event.slug} href={`/evenements/${event.slug}`} className="mini-event">
                      <span className="date-badge date-badge--sm">
                        <strong>{badge.day}</strong>
                        <span>{badge.month}</span>
                      </span>
                      <span>
                        <small>{event.format === 'online' ? 'Webinar live' : 'Événement'}</small>
                        <strong>{event.title}</strong>
                      </span>
                    </Link>
                  );
                })
              ) : (
                <div className="mini-event">
                  <span style={{ fontFamily: 'var(--font-text)', color: 'var(--muted)' }}>Les prochains événements arrivent bientôt.</span>
                </div>
              )}
            </div>
          </section>
        </div>
      </main>
      <SiteFooter />
    </>
  );
}

/** Photos d'experts et pointillés autour du titre (maquette Figma, page d'accueil), décoratifs. */
function HeroDecorations() {
  const experts: { index: number; left: number; top: number; tag?: string; tagLeft?: number; tagTop?: number; tagStyle?: 'lime' | 'white' }[] = [
    { index: 5, left: 70, top: 0, tag: 'Avocate', tagLeft: 138, tagTop: 24, tagStyle: 'lime' },
    { index: 2, left: 178, top: 196 },
    { index: 3, left: 45, top: 461, tag: 'Comptable', tagLeft: 118, tagTop: 509, tagStyle: 'white' },
    { index: 1, left: 1184, top: 8 },
    { index: 4, left: 1267, top: 352, tag: 'Juriste', tagLeft: 1227, tagTop: 396, tagStyle: 'lime' },
  ];
  return (
    <div className="hero-deco" aria-hidden="true">
      <div className="hero-deco__frame">
        <Image className="hero-deco__dash" src="/images/hero/dash-left.svg" alt="" width={113} height={433} style={{ left: 112, top: 83, width: 113, height: 433 }} />
        <Image className="hero-deco__dash" src="/images/hero/dash-right.svg" alt="" width={97} height={335} style={{ left: 1232, top: 74, width: 97, height: 335 }} />
        {experts.map((expert) => (
          <span key={expert.index}>
            <Image className="hero-deco__photo" src={`/images/hero/expert-${expert.index}.webp`} alt="" width={95} height={95} style={{ left: expert.left, top: expert.top }} />
            {expert.tag ? (
              <span className={`hero-deco__tag hero-deco__tag--${expert.tagStyle}`} style={{ left: expert.tagLeft, top: expert.tagTop }}>
                {expert.tag}
              </span>
            ) : null}
          </span>
        ))}
      </div>
    </div>
  );
}

