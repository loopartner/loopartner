<?php

use App\Models\AdminAuditLog;
use App\Models\Media;
use App\Modules\Events\Enums\EventNotice;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Notifications\EventNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
| Révisions : la modification d'un événement publié par un non-admin ne contourne jamais la
| validation. Le public continue de voir la version validée.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

describe('création d\'une révision', function (): void {
    it('enregistre la modification d\'un événement publié comme une révision en attente (202)', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer, ['version' => 3]);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Atelier : la TVA sans stress'])
            ->assertStatus(202)
            ->assertJsonPath('data.title', 'Atelier : bien démarrer sa comptabilité')
            ->assertJsonPath('data.version', 3)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.pending_revision.version', 1)
            ->assertJsonPath('data.pending_revision.changed_fields', ['title'])
            ->assertJsonPath('data.pending_revision.content.title', 'Atelier : la TVA sans stress');

        expect($event->fresh())->title->toBe('Atelier : bien démarrer sa comptabilité')->version->toBe(3)
            ->and(EventRevision::query()->sole())
            ->status->toBe(RevisionStatus::Pending)
            ->pending_key->toBe($event->id);
        expect(securityLog('events.revision_submitted')[0]->context)->toMatchArray(['event' => $event->uuid, 'changed_fields' => ['title']]);
    });

    it('fait passer par la révision le lien de connexion, les liens externes et la visibilité de l\'adresse', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer, ['format' => 'hybrid', 'online_url' => 'https://visio.exemple.fr/salle-validee']);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", [
            'online_url' => 'https://visio.autre-exemple.fr/nouvelle-salle',
            'external_url' => 'https://exemple.fr/programme',
            'address_visibility' => 'registrants',
        ])->assertStatus(202)
            ->assertJsonPath('data.pending_revision.changed_fields', ['address_visibility', 'online_url', 'external_url']);

        expect($event->fresh())
            ->online_url->toBe('https://visio.exemple.fr/salle-validee')
            ->external_url->toBeNull()
            ->address_visibility->value->toBe('public');
    });

    it('chiffre la version proposée en base', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer, ['format' => 'online', 'online_url' => 'https://visio.exemple.fr/a', 'venue_name' => null, 'address_line' => null, 'city' => null, 'country' => null, 'postal_code' => null]);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['online_url' => 'https://visio.exemple.fr/salle-confidentielle'])
            ->assertStatus(202);

        expect(DB::table('event_revisions')->value('payload'))->not->toContain('salle-confidentielle');
    });

    it('remplace la révision en attente et cumule les modifications successives', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);

        $first = $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Atelier : la TVA sans stress'])
            ->json('data.pending_revision.uuid');
        $this->travel(10)->minutes();
        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Villeurbanne'])
            ->assertStatus(202)
            ->assertJsonPath('data.pending_revision.uuid', $first)
            ->assertJsonPath('data.pending_revision.version', 2)
            ->assertJsonPath('data.pending_revision.changed_fields', ['title', 'city'])
            ->assertJsonPath('data.pending_revision.submitted_at', '2026-11-12T09:10:00Z');

        expect(EventRevision::query()->count())->toBe(1);
    });

    it('retire la révision quand la version proposée redevient identique à la version publiée', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Villeurbanne'])->assertStatus(202);
        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Lyon'])
            ->assertOk()
            ->assertJsonPath('data.pending_revision', null);

        expect(EventRevision::query()->sole()->status)->toBe(RevisionStatus::Superseded);
    });

    it('exige une version proposée complète et cohérente (422)', function (array $changes, string $field): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer, ['capacity' => 10]);
        registerTo($event);
        registerTo($event);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", $changes)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(EventRevision::query()->count())->toBe(0);
    })->with([
        'passage en ligne sans lien' => [['format' => 'online'], 'online_url'],
        'passage payant sans billetterie' => [['is_free' => false], 'ticket_url'],
        'capacité inférieure aux inscrits' => [['capacity' => 1], 'capacity'],
        'date passée' => [['starts_at' => '2026-11-12T08:00', 'ends_at' => '2026-11-12T12:00'], 'starts_at'],
        'lien vers une IP' => [['external_url' => 'https://192.0.2.10/'], 'external_url'],
    ]);

    it('refuse les champs fixés par la plateforme dans une modification (422)', function (string $field, mixed $value): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Nouveau titre de l\'atelier', $field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(EventRevision::query()->count())->toBe(0);
    })->with([['status', 'cancelled'], ['version', 1], ['slug', 'autre-slug'], ['published_at', '2026-01-01T00:00:00Z'], ['user_id', 1]]);

    it('refuse de modifier un événement déjà commencé (409)', function (): void {
        $organizer = eventOrganizer();
        $event = Event::factory()->published()->ongoing()->create(['user_id' => $organizer->id]);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Nouveau titre de l\'atelier'])
            ->assertConflict();
    });

    it('propose une nouvelle couverture sans toucher à celle qui est publiée', function (): void {
        Storage::fake('public');
        $organizer = eventOrganizer();
        $current = Media::factory()->create(['user_id' => $organizer->id, 'purpose' => 'event_cover', 'attached_at' => now()]);
        $event = publishedEventOf($organizer, ['cover_media_id' => $current->id]);
        $proposed = Media::factory()->create(['user_id' => $organizer->id, 'purpose' => 'event_cover']);

        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['cover' => $proposed->uuid])
            ->assertStatus(202)
            ->assertJsonPath('data.pending_revision.changed_fields', ['cover'])
            ->assertJsonPath('data.cover_url', $current->url())
            ->assertJsonPath('data.pending_revision.content.cover_url', $proposed->fresh()->url());

        expect($event->fresh()->cover_media_id)->toBe($current->id)
            ->and($proposed->fresh()->attached_at)->not->toBeNull();

        // Revenir à la couverture actuelle supprime la proposition.
        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['cover' => $current->uuid])->assertOk();
        expect(Media::query()->whereKey($proposed->id)->exists())->toBeFalse();
    });
});

describe('retrait d\'une révision', function (): void {
    it('retire la révision en attente (204), puis 409', function (): void {
        $organizer = eventOrganizer();
        $event = publishedEventOf($organizer);
        $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Villeurbanne'])->assertStatus(202);

        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$event->uuid}/revision")->assertNoContent();
        $this->actingAs($organizer)->deleteJson("/api/v1/me/events/{$event->uuid}/revision")->assertConflict();

        expect(EventRevision::query()->sole()->status)->toBe(RevisionStatus::Superseded)
            ->and($event->fresh()->city)->toBe('Lyon');
    });

    it('cache la révision d\'un autre organisateur (404)', function (): void {
        $event = publishedEventOf();

        $this->actingAs(eventOrganizer())->deleteJson("/api/v1/me/events/{$event->uuid}/revision")->assertNotFound();
    });
});

it('limite le nombre de révisions par organisateur (429)', function (): void {
    config(['security.rate_limits.event_revisions.per_hour' => 2]);
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);

    $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Villeurbanne'])->assertStatus(202);
    $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Lyon'])->assertOk();
    $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", ['city' => 'Vénissieux'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After');

    expect(securityLog('rate_limit.exceeded'))->toHaveCount(1);
});

describe('modification directe par un admin organisateur', function (): void {
    it('modifie directement son propre événement publié, avec une trace d\'audit, et prévient les inscrits d\'un changement de date', function (): void {
        $admin = moderator();
        $event = publishedEventOf($admin);
        $attendee = projectOwner('particulier');
        registerTo($event, $attendee);

        asModerator($admin)->patchJson("/api/v1/me/events/{$event->uuid}", ['starts_at' => '2026-11-25T18:30', 'ends_at' => '2026-11-25T20:30'])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-11-25T17:30:00Z')
            ->assertJsonPath('data.pending_revision', null);

        Notification::assertSentTo($attendee, EventNotification::class, fn (EventNotification $n): bool => $n->notice === EventNotice::Changed);
        expect(AdminAuditLog::query()->sole()->action)->toBe('events.event.updated_directly');
    });

    it('ne prévient pas les inscrits d\'une modification mineure', function (): void {
        $admin = moderator();
        $event = publishedEventOf($admin);
        registerTo($event);

        asModerator($admin)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Atelier : la TVA sans stress'])->assertOk();

        Notification::assertNothingSent();
    });

    it('refuse la modification directe à un admin sans session 2FA (403)', function (): void {
        $admin = moderator();
        $event = publishedEventOf($admin);

        $this->actingAs($admin)->patchJson("/api/v1/me/events/{$event->uuid}", ['title' => 'Atelier : la TVA sans stress'])->assertForbidden();

        expect($event->fresh()->title)->toBe('Atelier : bien démarrer sa comptabilité')
            ->and(EventRevision::query()->count())->toBe(0);
    });
});
