<?php

use App\Models\User;
use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\EventContent;
use App\Support\TwoFactorSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
| Module Events : autorisation de chaque endpoint (OWASP API1 BOLA, API5 BFLA).
|
| - Endpoints de l'organisateur : 404 pour tout autre utilisateur, quel que soit son rôle,
|   admin compris (la modération a ses propres endpoints).
| - Endpoints de modération : 404 pour tout rôle autre qu'admin, 403 pour un admin sans double
|   authentification (compte ou session).
| - Inscription : 403 pour l'organisateur lui-même et pour un utilisateur bloqué.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 09:00:00');
    Notification::fake();
});

/**
 * Événement publié avec un inscrit, une révision et une annonce en attente.
 *
 * @return array{event: Event, registration: string, revision: EventRevision, announcement: EventAnnouncement}
 */
function fullyLoadedEvent(): array
{
    $event = publishedEventOf();
    $registration = registerTo($event);

    $revision = new EventRevision;
    $revision->forceFill([
        'uuid' => (string) Str::uuid(), 'event_id' => $event->id, 'user_id' => $event->user_id, 'status' => 'pending',
        'payload' => [...EventContent::of($event), 'title' => 'Titre proposé dans la révision', 'cover' => 'keep'],
        'changed_fields' => ['title'], 'pending_key' => $event->id, 'submitted_at' => now(),
    ])->save();

    $announcement = EventAnnouncement::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'event_id' => $event->id, 'user_id' => $event->user_id,
        'body' => 'Le support est disponible sur docs.exemple.fr pour les inscrits.', 'has_links' => true, 'status' => 'pending_review',
    ]);

    return ['event' => $event, 'registration' => $registration->uuid, 'revision' => $revision, 'announcement' => $announcement];
}

/**
 * Endpoints de l'organisateur sur un événement : [méthode, URI, données].
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function organizerEndpoints(Event $event, string $registration): array
{
    $base = "/api/v1/me/events/{$event->uuid}";

    return [
        ['GET', $base, []],
        ['PATCH', $base, ['title' => 'Titre modifié par un autre utilisateur']],
        ['DELETE', $base, []],
        ['POST', "{$base}/submit", []],
        ['POST', "{$base}/withdraw", []],
        ['DELETE', "{$base}/revision", []],
        ['POST', "{$base}/cancel", []],
        ['GET', "{$base}/registrations", []],
        ['DELETE', "{$base}/registrations/{$registration}", []],
        ['GET', "{$base}/announcements", []],
        ['POST', "{$base}/announcements", ['body' => 'Annonce envoyée par un autre utilisateur que l\'organisateur.']],
    ];
}

/**
 * Endpoints de modération : [méthode, URI, données].
 *
 * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
 */
function moderationEndpoints(Event $event, EventRevision $revision, EventAnnouncement $announcement): array
{
    $reason = ['reason' => 'Motif de refus suffisamment détaillé.'];

    return [
        ['GET', '/api/v1/admin/events/queue', []],
        ['GET', "/api/v1/admin/events/{$event->uuid}", []],
        ['POST', "/api/v1/admin/events/{$event->uuid}/approve", ['version' => 1]],
        ['POST', "/api/v1/admin/events/{$event->uuid}/reject", ['version' => 1, ...$reason]],
        ['POST', "/api/v1/admin/events/{$event->uuid}/cancel", $reason],
        ['GET', "/api/v1/admin/event-revisions/{$revision->uuid}", []],
        ['POST', "/api/v1/admin/event-revisions/{$revision->uuid}/approve", ['version' => 1]],
        ['POST', "/api/v1/admin/event-revisions/{$revision->uuid}/reject", ['version' => 1, ...$reason]],
        ['GET', "/api/v1/admin/event-announcements/{$announcement->uuid}", []],
        ['POST', "/api/v1/admin/event-announcements/{$announcement->uuid}/approve", []],
        ['POST', "/api/v1/admin/event-announcements/{$announcement->uuid}/reject", $reason],
    ];
}

/**
 * L'état de l'événement, de sa révision, de son annonce et de son inscription n'a pas changé.
 *
 * @param  array{event: Event, registration: string, revision: EventRevision, announcement: EventAnnouncement}  $loaded
 */
function expectUntouched(array $loaded): void
{
    expect($loaded['event']->fresh())
        ->status->toBe(EventStatus::Published)
        ->title->toBe('Atelier : bien démarrer sa comptabilité')
        ->registrations_count->toBe(1)
        ->and($loaded['revision']->fresh()->status->value)->toBe('pending')
        ->and($loaded['announcement']->fresh()->status->value)->toBe('pending_review')
        ->and(EventAnnouncement::query()->count())->toBe(1);
}

it('cache chaque endpoint de l\'organisateur à tout autre utilisateur (404), quel que soit son rôle', function (string $role): void {
    $loaded = fullyLoadedEvent();
    $other = $role === 'admin 2FA' ? moderator() : eventOrganizer($role === 'particulier' ? 'entrepreneur' : $role);

    foreach (organizerEndpoints($loaded['event'], $loaded['registration']) as [$method, $uri, $data]) {
        $request = $role === 'admin 2FA' ? asModerator($other) : $this->actingAs($other);

        expect($request->json($method, $uri, $data)->status())->toBe(404, "{$method} {$uri}");
    }

    expectUntouched($loaded);
})->with(['prestataire', 'entrepreneur', 'redacteur', 'admin', 'admin 2FA']);

it('refuse les endpoints de l\'organisateur aux rôles qui n\'organisent pas (404 sur un événement, 403 pour la liste et la création)', function (): void {
    $loaded = fullyLoadedEvent();
    $particulier = projectOwner('particulier');

    foreach (organizerEndpoints($loaded['event'], $loaded['registration']) as [$method, $uri, $data]) {
        expect($this->actingAs($particulier)->json($method, $uri, $data)->status())->toBe(404, "{$method} {$uri}");
    }

    $this->actingAs($particulier)->getJson('/api/v1/me/events')->assertForbidden();
    $this->actingAs($particulier)->postJson('/api/v1/me/events', eventPayload())->assertForbidden();
    expectUntouched($loaded);
});

it('cache chaque endpoint de modération aux rôles autres qu\'admin (404), même avec la 2FA', function (string $role): void {
    $loaded = fullyLoadedEvent();
    $user = userWithTwoFactor();
    $user->assignRole($role);

    foreach (moderationEndpoints($loaded['event'], $loaded['revision'], $loaded['announcement']) as [$method, $uri, $data]) {
        expect(asModerator($user)->json($method, $uri, $data)->status())->toBe(404, "{$method} {$uri}");
    }

    expectUntouched($loaded);
    expect(securityLog('moderation.access_denied'))->not->toBeEmpty();
})->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);

it('refuse chaque endpoint de modération à un admin sans double authentification (403)', function (string $case): void {
    $loaded = fullyLoadedEvent();

    $request = match ($case) {
        'sans 2FA' => fromFrontend()->actingAs(projectOwner('admin'))->withSession([TwoFactorSession::KEY => time()]),
        'session sans code' => fromFrontend()->actingAs(moderator()),
        'email non vérifié' => asModerator(moderator(['email_verified_at' => null])),
    };

    foreach (moderationEndpoints($loaded['event'], $loaded['revision'], $loaded['announcement']) as [$method, $uri, $data]) {
        expect($request->json($method, $uri, $data)->status())->toBe(403, "{$method} {$uri}");
    }

    expectUntouched($loaded);
})->with(['sans 2FA', 'session sans code', 'email non vérifié']);

it('ouvre la modération à un admin dont la session a présenté un code 2FA', function (): void {
    $loaded = fullyLoadedEvent();
    $admin = moderator();

    foreach (moderationEndpoints($loaded['event'], $loaded['revision'], $loaded['announcement']) as [$method, $uri]) {
        if ($method === 'GET') {
            expect(asModerator($admin)->json($method, $uri)->status())->toBe(200, $uri);
        }
    }
});

it('refuse l\'inscription d\'un utilisateur bloqué dans un sens ou dans l\'autre, et de l\'organisateur (403)', function (): void {
    $organizer = eventOrganizer();
    $event = publishedEventOf($organizer);
    [$blocked, $blocker] = [projectOwner('particulier'), projectOwner('entrepreneur')];
    app(BlockUser::class)->handle($organizer, $blocked->id);
    app(BlockUser::class)->handle($blocker, $organizer->id);

    foreach ([$blocked, $blocker, $organizer] as $user) {
        $this->actingAs($user)->postJson("/api/v1/events/{$event->slug}/registration")->assertForbidden();
    }

    expect($event->fresh()->registrations_count)->toBe(0);
});

it('refuse toute route authentifiée du module à un utilisateur dont l\'email n\'est pas vérifié (403)', function (): void {
    $user = projectOwner('entrepreneur', verified: false);
    $event = Event::factory()->create(['user_id' => $user->id]);
    $published = publishedEventOf();

    $this->actingAs($user)->getJson('/api/v1/me/events')->assertForbidden();
    $this->actingAs($user)->getJson("/api/v1/me/events/{$event->uuid}")->assertForbidden();
    $this->actingAs($user)->postJson("/api/v1/events/{$published->slug}/registration")->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/me/event-registrations')->assertForbidden();
});

it('ne laisse jamais un organisateur publier, changer le statut ou appliquer une révision lui-même', function (): void {
    $organizer = eventOrganizer();
    $draft = Event::factory()->create(['user_id' => $organizer->id]);
    $published = publishedEventOf($organizer);

    foreach ([$draft, $published] as $event) {
        foreach (['status' => 'published', 'published_at' => '2026-11-01T00:00:00Z', 'version' => 99, 'slug' => 'mon-slug', 'user_id' => User::factory()->create()->id] as $field => $value) {
            $this->actingAs($organizer)->patchJson("/api/v1/me/events/{$event->uuid}", [$field => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors([$field]);
        }
    }

    // Aucune route organisateur n'applique une révision ni ne publie.
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$draft->uuid}/publish")->assertNotFound();
    $this->actingAs($organizer)->postJson("/api/v1/me/events/{$published->uuid}/approve")->assertNotFound();
    $this->actingAs($organizer)->postJson("/api/v1/admin/events/{$draft->uuid}/approve", ['version' => 1])->assertNotFound();

    expect($draft->fresh()->status)->toBe(EventStatus::Draft)
        ->and(array_intersect((new Event)->getFillable(), ['status', 'slug', 'version', 'user_id', 'published_at', 'cover_media_id', 'registrations_count']))->toBe([]);
});
