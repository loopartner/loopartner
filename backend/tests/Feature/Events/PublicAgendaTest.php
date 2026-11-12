<?php

use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Agenda public (GET /events, GET /events/{slug}, fichier .ics) : uniquement les événements
| validés par un admin, jamais le lien de connexion.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

describe('liste', function (): void {
    it('liste les événements publiés à venir ou en cours, triés par date de début', function (): void {
        $later = publishedEventOf(null, ['starts_at' => now()->addDays(20), 'ends_at' => now()->addDays(20)->addHours(2)]);
        $sooner = publishedEventOf(null, ['starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHours(2)]);
        $ongoing = Event::factory()->published()->ongoing()->create();

        foreach (['draft', 'pendingReview', 'rejected', 'past'] as $state) {
            Event::factory()->{$state}()->create();
        }
        Event::factory()->published()->create(['status' => EventStatus::Cancelled]);
        // Terminé mais pas encore passé au statut "past" par la tâche planifiée.
        Event::factory()->published()->ended()->create();

        $this->getJson('/api/v1/events')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.slug', $ongoing->slug)
            ->assertJsonPath('data.1.slug', $sooner->slug)
            ->assertJsonPath('data.2.slug', $later->slug)
            ->assertJsonStructure(['data', 'links', 'meta']);
    });

    it('filtre par type, format, pays, ville (sans casse), langue et gratuité', function (string $query, array $expected): void {
        $workshop = publishedEventOf(null, ['title' => 'Atelier lyonnais']);
        $webinar = Event::factory()->published()->online()->create(['title' => 'Webinaire en anglais', 'language' => 'en']);
        $fair = Event::factory()->published()->paid()->create(['title' => 'Salon parisien', 'type' => 'trade_show', 'city' => 'Paris']);
        $brussels = publishedEventOf(null, ['title' => 'Rencontre bruxelloise', 'type' => 'networking', 'city' => 'Bruxelles', 'country' => 'BE']);
        $titles = ['workshop' => $workshop->title, 'webinar' => $webinar->title, 'fair' => $fair->title, 'brussels' => $brussels->title];

        $response = $this->getJson('/api/v1/events?'.$query)->assertOk();

        expect(collect($response->json('data'))->pluck('title')->sort()->values()->all())
            ->toBe(collect($expected)->map(fn (string $key): string => $titles[$key])->sort()->values()->all());
    })->with([
        'type' => ['type=trade_show', ['fair']],
        'format' => ['format=online', ['webinar']],
        'pays' => ['country=be', ['brussels']],
        'ville sans casse' => ['city=LYON', ['workshop']],
        'langue' => ['language=en', ['webinar']],
        'gratuit' => ['free=true', ['workshop', 'webinar', 'brussels']],
        'payant' => ['free=0', ['fair']],
        'paramètre vide ignoré' => ['country=&city=', ['workshop', 'webinar', 'fair', 'brussels']],
    ]);

    it('filtre par période (événements qui se déroulent au moins en partie pendant la période)', function (): void {
        $november = publishedEventOf(null, ['starts_at' => '2026-11-20 17:00:00', 'ends_at' => '2026-11-20 19:00:00']);
        $multiDay = publishedEventOf(null, ['starts_at' => '2026-11-30 08:00:00', 'ends_at' => '2026-12-02 18:00:00']);
        publishedEventOf(null, ['starts_at' => '2026-12-15 17:00:00', 'ends_at' => '2026-12-15 19:00:00']);

        $this->getJson('/api/v1/events?from=2026-12-01&to=2026-12-10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $multiDay->slug);
        $this->getJson('/api/v1/events?to=2026-11-25')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $november->slug);
    });

    it('filtre par catégorie, sous-catégories comprises', function (): void {
        $parent = serviceCategory('gestion');
        $child = serviceCategory('comptabilite', parent: $parent);
        $tagged = publishedEventOf();
        $tagged->categories()->attach($child);
        publishedEventOf();

        $this->getJson('/api/v1/events?category=gestion')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $tagged->slug)
            ->assertJsonPath('data.0.categories.0.slug', 'comptabilite');
    });

    it('recherche dans le titre, la description et la ville, jokers échappés', function (): void {
        $match = publishedEventOf(null, ['title' => 'Atelier : 100% financement']);
        publishedEventOf(null, ['title' => 'Atelier : 100 euros de budget']);

        $this->getJson('/api/v1/events?q='.urlencode('100%'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', $match->slug);
    });

    it('refuse les filtres invalides (422)', function (string $query, string $field): void {
        $this->getJson('/api/v1/events?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    })->with([
        ['q=a', 'q'],
        ['q='.str_repeat('a', 101), 'q'],
        ['from=2026-12-10&to=2026-12-01', 'to'],
        ['from=2026-01-01&to=2027-06-01', 'to'],
        ['from=12/01/2026', 'from'],
        ['type=concert', 'type'],
        ['country=XX', 'country'],
        ['language=xx', 'language'],
        ['category=inconnue', 'category'],
        ['free=peut-etre', 'free'],
        ['per_page=51', 'per_page'],
    ]);

    it('n\'expose ni lien de connexion, ni uuid, ni donnée personnelle de l\'organisateur', function (): void {
        $organizer = eventOrganizer();
        Event::factory()->published()->online()->create(['user_id' => $organizer->id, 'online_url' => 'https://visio.exemple.fr/salle-secrete']);

        $response = $this->getJson('/api/v1/events')->assertOk();

        expectNoPrivateFields($response->json());
        expect($response->getContent())
            ->not->toContain('salle-secrete')
            ->not->toContain('online_url')
            ->not->toContain('"uuid"')
            ->not->toContain($organizer->email)
            ->not->toContain($organizer->name);
    });
});

describe('détail', function (): void {
    it('présente un prestataire par son profil public et les autres par le nom choisi', function (): void {
        $provider = eventOrganizer('prestataire');
        $byProvider = publishedEventOf($provider, ['organizer_name' => null]);
        $named = publishedEventOf(null, ['organizer_name' => 'Collectif des indépendants']);
        $profile = ProviderProfile::query()->where('user_id', $provider->id)->sole();

        $this->getJson("/api/v1/events/{$byProvider->slug}")
            ->assertOk()
            ->assertJsonPath('data.organizer.kind', 'provider')
            ->assertJsonPath('data.organizer.name', $profile->display_name)
            ->assertJsonPath('data.organizer.slug', $profile->slug);
        $this->getJson("/api/v1/events/{$named->slug}")
            ->assertJsonPath('data.organizer', ['kind' => 'named', 'name' => 'Collectif des indépendants', 'slug' => null, 'headline' => null, 'logo_url' => null]);
    });

    it('annonce avant l\'inscription que le nom de l\'inscrit sera visible par l\'organisateur', function (): void {
        $event = publishedEventOf(null, ['capacity' => 10]);
        registerTo($event);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.registration.mode', 'internal')
            ->assertJsonPath('data.registration.is_open', true)
            ->assertJsonPath('data.registration.spots_left', 9)
            ->assertJsonPath('data.registration.privacy.visible_to_organizer', ['name', 'account_type'])
            ->assertJsonPath('data.registration.privacy.notice', fn (string $notice): bool => str_contains($notice, 'votre nom et votre type de compte seront visibles par l\'organisateur'));

        $this->withHeader('Accept-Language', 'en-GB')->getJson("/api/v1/events/{$event->slug}")
            ->assertJsonPath('data.registration.privacy.notice', fn (string $notice): bool => str_contains($notice, 'your name and account type will be visible to the organiser'));
    });

    it('renvoie vers la billetterie externe pour un événement payant', function (): void {
        $event = Event::factory()->published()->paid()->create();

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertJsonPath('data.registration.mode', 'external')
            ->assertJsonPath('data.registration.is_open', false)
            ->assertJsonPath('data.registration.privacy', null)
            ->assertJsonPath('data.ticket_url', 'https://billetterie.exemple.fr/salon');
    });

    it('masque l\'adresse et le code postal réservés aux inscrits', function (): void {
        $event = publishedEventOf(null, ['address_visibility' => 'registrants']);

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.venue', [
                'name' => 'La Cordée',
                'address_line' => null,
                'postal_code' => null,
                'city' => 'Lyon',
                'country' => 'FR',
                'address_visibility' => 'registrants',
            ]);
    });

    it('montre un événement passé, sans inscription possible', function (): void {
        $event = Event::factory()->past()->create();

        $this->getJson("/api/v1/events/{$event->slug}")
            ->assertOk()
            ->assertJsonPath('data.status', 'past')
            ->assertJsonPath('data.registration.is_open', false);
    });

    it('répond 404 pour un événement non publié, en attente, refusé, annulé ou inconnu', function (string $state): void {
        $event = Event::factory()->{$state}()->create(['slug' => 'evenement-cache']);

        if ($state === 'published') {
            $event->forceFill(['status' => EventStatus::Cancelled])->save();
        }

        $this->getJson('/api/v1/events/evenement-cache')->assertNotFound()->assertJsonPath('message', 'Événement introuvable.');
        $this->getJson('/api/v1/events/evenement-cache/calendar.ics')->assertNotFound();
        $this->getJson('/api/v1/events/inconnu-abc123')->assertNotFound();
    })->with(['draft', 'pendingReview', 'rejected', 'published']);
});

describe('fichier calendrier', function (): void {
    it('produit un fichier iCalendar sans lien de connexion, texte échappé et lignes repliées', function (): void {
        $event = Event::factory()->published()->create([
            'format' => 'hybrid',
            'online_url' => 'https://visio.exemple.fr/salle-secrete',
            'title' => 'Atelier ; compta, TVA',
            'description' => "Programme :\nBEGIN:VEVENT\nSUMMARY:Faux événement injecté".str_repeat(' long texte é', 20),
        ]);

        $response = $this->get("/api/v1/events/{$event->slug}/calendar.ics")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/calendar; charset=utf-8')
            ->assertHeader('Content-Disposition', 'attachment; filename="'.$event->slug.'.ics"');

        $ics = (string) $response->getContent();
        $lines = explode("\r\n", rtrim($ics, "\r\n"));

        expect($ics)->not->toContain('salle-secrete')
            ->toContain('SUMMARY:Atelier \\; compta\\, TVA')
            ->toContain('DTSTART:20261122T160000Z')
            ->toContain('UID:'.$event->slug.'@')
            ->and(collect($lines)->filter(fn (string $line): bool => $line === 'BEGIN:VEVENT'))->toHaveCount(1)
            ->and(collect($lines)->every(fn (string $line): bool => strlen($line) <= 75 && mb_check_encoding($line, 'UTF-8')))->toBeTrue();
    });

    it('masque l\'adresse réservée aux inscrits dans le fichier public', function (): void {
        $event = publishedEventOf(null, ['address_visibility' => 'registrants']);

        $ics = (string) $this->get("/api/v1/events/{$event->slug}/calendar.ics")->assertOk()->getContent();

        expect($ics)->toContain('LOCATION:La Cordée\\, Lyon\\, FR')
            ->not->toContain('République')
            ->not->toContain('69001');
    });
});
