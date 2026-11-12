<?php

use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Models\User;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Événements de l'organisateur (/me/events) : brouillon, soumission à la validation,
| publication directe d'un admin, annulation.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
    serviceCategory('comptabilite');
});

describe('création', function (): void {
    it('crée un brouillon pour chaque rôle organisateur, dates converties en UTC', function (string $role): void {
        $organizer = eventOrganizer($role);
        $payload = eventPayload(['categories' => ['comptabilite']]);

        if ($role === 'prestataire') {
            unset($payload['organizer_name']);
        }

        $this->actingAs($organizer)->postJson('/api/v1/me/events', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', null)
            ->assertJsonPath('data.version', 1)
            // 22 novembre 2026, 18 h 30 à Paris (UTC+1).
            ->assertJsonPath('data.starts_at', '2026-11-22T17:30:00Z')
            ->assertJsonPath('data.ends_at', '2026-11-22T19:30:00Z')
            ->assertJsonPath('data.timezone', 'Europe/Paris')
            ->assertJsonPath('data.local.starts_at', '2026-11-22T18:30')
            ->assertJsonPath('data.venue.city', 'Lyon')
            ->assertJsonPath('data.categories.0.slug', 'comptabilite');

        expect(Event::query()->sole())
            ->user_id->toBe($organizer->id)
            ->status->toBe(EventStatus::Draft);
    })->with(['prestataire', 'entrepreneur', 'redacteur', 'admin']);

    it('refuse les rôles qui n\'organisent pas d\'événements', function (): void {
        $this->actingAs(projectOwner('particulier'))->postJson('/api/v1/me/events', eventPayload())->assertForbidden();
        $this->actingAs(projectOwner('particulier'))->getJson('/api/v1/me/events')->assertForbidden();

        expect(Event::query()->count())->toBe(0);
    });

    it('exige un profil prestataire publié', function (): void {
        $this->actingAs(projectOwner('prestataire'))
            ->postJson('/api/v1/me/events', eventPayload(['organizer_name' => null]))
            ->assertForbidden()
            ->assertJsonPath('message', 'Publiez votre profil prestataire, avec au moins une catégorie, pour proposer un événement.');
    });

    it('exige un email vérifié', function (): void {
        $this->actingAs(projectOwner('entrepreneur', verified: false))->postJson('/api/v1/me/events', eventPayload())->assertForbidden();
    });

    it('refuse un nom d\'organisateur pour un prestataire, présenté par son profil', function (): void {
        $this->actingAs(eventOrganizer('prestataire'))
            ->postJson('/api/v1/me/events', eventPayload())
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['organizer_name']);
    });

    it('refuse les entrées invalides ou malveillantes', function (array $overrides, string $field): void {
        $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(Event::query()->count())->toBe(0);
    })->with([
        'HTML dans le titre' => [['title' => 'Atelier <script>alert(1)</script>'], 'title'],
        'caractère invisible' => [['title' => "Atelier\u{202E}compta"], 'title'],
        'lien http' => [['external_url' => 'http://exemple.fr'], 'external_url'],
        'raccourcisseur' => [['external_url' => 'https://bit.ly/abc'], 'external_url'],
        'adresse IP' => [['format' => 'online', 'online_url' => 'https://10.0.0.1/visio'], 'online_url'],
        'raccourcisseur dans la description' => [['description' => 'Toutes les informations sur bit.ly/atelier-compta, à très bientôt !'], 'description'],
        'type inconnu' => [['type' => 'concert'], 'type'],
        'langue inconnue' => [['language' => 'xx'], 'language'],
        'fuseau inconnu' => [['timezone' => 'Mars/Olympus'], 'timezone'],
        'date sans heure' => [['starts_at' => '2026-11-22'], 'starts_at'],
        'fin avant le début' => [['ends_at' => '2026-11-22T17:00'], 'ends_at'],
        'durée de plus de 31 jours' => [['ends_at' => '2027-01-22T18:30'], 'ends_at'],
        'heure inexistante (passage à l\'heure d\'été)' => [['starts_at' => '2027-03-28T02:30', 'ends_at' => '2027-03-28T05:00'], 'starts_at'],
        'capacité au-delà du plafond' => [['capacity' => 301], 'capacity'],
        'trop de catégories' => [['categories' => ['a', 'b', 'c', 'd']], 'categories'],
        'catégorie inconnue' => [['categories' => ['inconnue']], 'categories.0'],
        'code postal' => [['postal_code' => '<69001>'], 'postal_code'],
    ]);

    it('refuse les champs fixés par la plateforme (statut, slug, propriétaire…)', function (string $field, mixed $value): void {
        $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(Event::query()->count())->toBe(0);
    })->with([
        ['status', 'published'],
        ['slug', 'mon-evenement'],
        ['version', 99],
        ['user_id', 1],
        ['published_at', '2026-11-01T00:00:00Z'],
        ['registrations_count', 500],
        ['cover_media_id', 1],
        ['uuid', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'],
    ]);

    it('retire les champs incompatibles avec le format et la gratuité', function (): void {
        $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload([
            'format' => 'online',
            'online_url' => 'https://visio.exemple.fr/salle',
            'ticket_url' => 'https://billetterie.exemple.fr',
        ]))->assertCreated()
            ->assertJsonPath('data.venue', null)
            ->assertJsonPath('data.ticket_url', null)
            ->assertJsonPath('data.online_url', 'https://visio.exemple.fr/salle');

        expect(Event::query()->sole())->city->toBeNull()->ticket_url->toBeNull();
    });

    it('chiffre le lien de connexion en base', function (): void {
        $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload([
            'format' => 'online',
            'online_url' => 'https://visio.exemple.fr/salle-secrete',
        ]))->assertCreated();

        expect(DB::table('events')->value('online_url'))->not->toContain('salle-secrete')
            ->and(Event::query()->sole()->online_url)->toBe('https://visio.exemple.fr/salle-secrete');
    });

    it('rattache une image de couverture envoyée par la brique média', function (): void {
        Storage::fake('public');
        $organizer = eventOrganizer();
        $uuid = $this->actingAs($organizer)
            ->post('/api/v1/media', ['file' => testImage('jpeg', 1600, 900), 'purpose' => 'event_cover'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->json('data.uuid');

        $this->actingAs($organizer)->postJson('/api/v1/me/events', eventPayload(['cover' => $uuid]))
            ->assertCreated()
            ->assertJsonPath('data.cover_url', fn (?string $url): bool => is_string($url) && str_contains($url, $uuid));

        expect(Media::query()->sole()->attached_at)->not->toBeNull();
    });

    it('refuse la couverture d\'un autre utilisateur', function (): void {
        $media = Media::factory()->create(['purpose' => 'event_cover']);

        $this->actingAs(eventOrganizer())->postJson('/api/v1/me/events', eventPayload(['cover' => $media->uuid]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cover']);
    });
});

describe('consultation et modification', function (): void {
    it('liste uniquement ses propres événements, filtrables par statut', function (): void {
        $organizer = eventOrganizer();
        Event::factory()->create(['user_id' => $organizer->id]);
        Event::factory()->pendingReview()->create(['user_id' => $organizer->id]);
        Event::factory()->create();

        $this->actingAs($organizer)->getJson('/api/v1/me/events')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($organizer)->getJson('/api/v1/me/events?status=pending_review')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending_review');
        $this->actingAs($organizer)->getJson('/api/v1/me/events?status=inconnu')->assertUnprocessable();
    });

    it('cache l\'événement d\'un autre organisateur derrière un 404, admin compris', function (string $role): void {
        $event = Event::factory()->create();
        $other = eventOrganizer($role);

        $this->actingAs($other)->getJson("/api/v1/me/events/{$event->uuid}")->assertNotFound();
        $this->actingAs($other)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Piraté par un autre'])->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/me/events/{$event->uuid}")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/me/events/{$event->uuid}/submit")->assertNotFound();

        expect($event->fresh()->title)->not->toBe('Piraté par un autre');
    })->with(['entrepreneur', 'redacteur', 'admin']);

    it('modifie un brouillon et augmente sa version', function (): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Atelier : la TVA sans stress'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Atelier : la TVA sans stress')
            ->assertJsonPath('data.version', 2);
    });

    it('conserve l\'heure locale quand seul le fuseau change', function (): void {
        $organizer = eventOrganizer();
        $uuid = $this->actingAs($organizer)->postJson('/api/v1/me/events', eventPayload())->json('data.uuid');

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$uuid}", ['timezone' => 'America/Toronto'])
            ->assertOk()
            ->assertJsonPath('data.local.starts_at', '2026-11-22T18:30')
            ->assertJsonPath('data.starts_at', '2026-11-22T23:30:00Z');
    });

    it('refuse de modifier un événement en attente de validation, annulé ou passé (409)', function (string $state): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->{$state}()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Nouveau titre de l\'atelier'])
            ->assertConflict();
    })->with(['pendingReview', 'past']);

    it('supprime un brouillon et sa couverture, mais pas un événement publié', function (): void {
        $organizer = eventOrganizer();
        $draft = Event::factory()->create(['user_id' => $organizer->id]);
        $published = publishedEventOf($organizer);

        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$draft->uuid}")->assertNoContent();
        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$published->uuid}")->assertConflict();

        expect(Event::query()->pluck('id')->all())->toBe([$published->id]);
    });
});

describe('soumission à la validation', function (): void {
    it('soumet un événement complet (202) et le journalise', function (): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/submit")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.slug', null);

        expect(securityLog('events.submitted')[0]->context)->toMatchArray(['event' => $event->uuid, 'user_id' => $organizer->id]);
    });

    it('refuse un événement incomplet (422)', function (array $attributes, string $field): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->create(['user_id' => $organizer->id, ...$attributes]);

        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect($event->fresh()->status)->toBe(EventStatus::Draft);
    })->with([
        'en ligne sans lien' => [['format' => 'online', 'venue_name' => null, 'city' => null, 'country' => null, 'address_line' => null], 'online_url'],
        'présentiel sans ville' => [['city' => null], 'city'],
        'payant sans billetterie' => [['is_free' => false], 'ticket_url'],
        'sans nom d\'organisateur' => [['organizer_name' => null], 'organizer_name'],
        'commence dans moins d\'une heure' => [['starts_at' => '2026-11-12 09:30:00', 'ends_at' => '2026-11-12 11:00:00'], 'starts_at'],
    ]);

    it('limite le nombre d\'événements en attente par organisateur (409)', function (): void {
        $organizer = eventOrganizer();
        Event::factory()->pendingReview()->count(3)->create(['user_id' => $organizer->id]);
        $event = Event::factory()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/submit")->assertConflict();

        expect($event->fresh()->status)->toBe(EventStatus::Draft)
            ->and(securityLog('events.pending_limit_reached'))->toHaveCount(1);
    });

    it('retire une soumission : l\'événement redevient un brouillon modifiable', function (): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->pendingReview()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/withdraw")->assertConflict();
    });

    it('resoumet un événement refusé après correction', function (): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->rejected()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['description' => 'Programme : tenue des comptes, factures, TVA, et questions-réponses avec une experte.'])->assertOk();
        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/submit")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'pending_review');
    });
});

describe('publication directe par un admin', function (): void {
    it('refuse un admin sans double authentification (403)', function (): void {
        $admin = projectOwner('admin');
        $event = Event::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)->postJson("/api/v1/me/events/{$event->uuid}/submit")
            ->assertForbidden();

        expect($event->fresh()->status)->toBe(EventStatus::Draft);
    });

    it('publie directement l\'événement d\'un admin dont la session a passé la 2FA, avec un slug et une trace d\'audit', function (): void {
        $admin = moderator();
        $event = Event::factory()->create(['user_id' => $admin->id]);

        asModerator($admin)->postJson("/api/v1/me/events/{$event->uuid}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.slug', fn (string $slug): bool => str_starts_with($slug, 'atelier-bien-demarrer-sa-comptabilite-'));

        expect(AdminAuditLog::query()->sole())
            ->action->toBe('events.event.published_directly')
            ->subject_uuid->toBe($event->uuid)
            ->admin_id->toBe($admin->id);
    });
});

describe('annulation par l\'organisateur', function (): void {
    it('annule immédiatement un événement publié et prévient chaque inscrit', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        $attendees = [projectOwner('particulier'), projectOwner('entrepreneur')];
        foreach ($attendees as $attendee) {
            registerTo($event, $attendee);
        }

        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/cancel", ['reason' => 'Intervenante malade, l\'atelier sera reprogrammé.'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_by', 'organizer');

        foreach ($attendees as $attendee) {
            Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Cancelled);
        }
        Notification::assertNotSentTo($organizer, EventNotification::class);
        expect($event->fresh()->cancelled_by)->toBe(CancelledBy::Organizer)
            ->and(securityLog('events.cancelled')[0]->context)->toMatchArray(['event' => $event->uuid, 'by' => 'organizer']);
    });

    it('refuse d\'annuler un brouillon ou un événement terminé (409)', function (string $state): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->{$state}()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->postJson("/api/v1/me/events/{$event->uuid}/cancel")->assertConflict();
    })->with(['draft' => 'pendingReview', 'passé' => 'past']);

    it('refuse l\'annulation de l\'événement d\'un autre (404)', function (): void {
        $event = publishedEventOf();

        $this->actingAs(eventOrganizer())->postJson("/api/v1/me/events/{$event->uuid}/cancel")->assertNotFound();

        expect($event->fresh()->status)->toBe(EventStatus::Published);
    });
});

it('n\'expose ni identifiant interne ni donnée personnelle de l\'organisateur', function (): void {
    $organizer = eventOrganizer();
    $event = Event::factory()->create(['user_id' => $organizer->id]);

    $data = $this->actingAs($organizer)->getJson("/api/v1/me/events/{$event->uuid}")->assertOk()->json('data');

    expectNoPrivateFields($data);
    expect(json_encode($data))->not->toContain($organizer->email)->not->toContain('cover_media_id');
});

it('ne laisse jamais User déclarer de relation vers les événements', function (): void {
    expect(method_exists(User::class, 'events'))->toBeFalse();
});
