<?php

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
| Matrice d'autorisation transversale (OWASP API1 BOLA, API3 propriétés, API5 BFLA).
|
| ROUTES est l'inventaire de toutes les routes de l'API : une nouvelle route fait échouer le test
| tant qu'elle n'y est pas classée (publique ou authentifiée). Chaque route authentifiée doit
| répondre 401 à un visiteur anonyme.
*/

/**
 * "MÉTHODE uri" => "public" ou "auth".
 */
const API_ROUTES = [
    'GET api/v1/categories' => 'public',
    'GET api/v1/providers' => 'public',
    'GET api/v1/providers/{slug}' => 'public',
    'GET api/v1/events' => 'public',
    'GET api/v1/events/{slug}' => 'public',
    'GET api/v1/events/{slug}/calendar.ics' => 'public',
    'POST api/v1/auth/register' => 'public',
    'GET api/v1/auth/email/verify/{id}/{hash}' => 'public',
    'POST api/v1/auth/login' => 'public',
    'POST api/v1/auth/forgot-password' => 'public',
    'POST api/v1/auth/reset-password' => 'public',
    'POST api/v1/auth/two-factor-challenge' => 'public',
    'POST api/v1/auth/logout' => 'auth',
    'POST api/v1/auth/email/verification-notification' => 'auth',
    'PUT api/v1/auth/user/password' => 'auth',
    'POST api/v1/auth/user/confirm-password' => 'auth',
    'GET api/v1/auth/user/confirmed-password-status' => 'auth',
    'POST api/v1/auth/user/two-factor-authentication' => 'auth',
    'DELETE api/v1/auth/user/two-factor-authentication' => 'auth',
    'POST api/v1/auth/user/confirmed-two-factor-authentication' => 'auth',
    'GET api/v1/auth/user/two-factor-qr-code' => 'auth',
    'GET api/v1/auth/user/two-factor-secret-key' => 'auth',
    'GET api/v1/auth/user/two-factor-recovery-codes' => 'auth',
    'POST api/v1/auth/user/two-factor-recovery-codes' => 'auth',
    'GET api/v1/me' => 'auth',
    'PATCH api/v1/me' => 'auth',
    'DELETE api/v1/me' => 'auth',
    'GET api/v1/me/provider-profile' => 'auth',
    'PUT api/v1/me/provider-profile' => 'auth',
    'POST api/v1/media' => 'auth',
    'GET api/v1/me/projects' => 'auth',
    'POST api/v1/me/projects' => 'auth',
    'GET api/v1/me/projects/{uuid}' => 'auth',
    'PATCH api/v1/me/projects/{uuid}' => 'auth',
    'DELETE api/v1/me/projects/{uuid}' => 'auth',
    'POST api/v1/me/projects/{uuid}/publish' => 'auth',
    'POST api/v1/me/projects/{uuid}/close' => 'auth',
    'GET api/v1/projects' => 'auth',
    'GET api/v1/projects/{uuid}' => 'auth',
    // Mise en relation et messagerie (voir aussi MatchingAuthorizationTest).
    'POST api/v1/projects/{uuid}/proposals' => 'auth',
    'POST api/v1/providers/{slug}/contact' => 'auth',
    'GET api/v1/me/match-requests' => 'auth',
    'GET api/v1/me/match-requests/{uuid}' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/accept' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/decline' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/withdraw' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/report' => 'auth',
    'POST api/v1/me/match-requests/{uuid}/block' => 'auth',
    'GET api/v1/me/conversations' => 'auth',
    'GET api/v1/me/conversations/unread' => 'auth',
    'GET api/v1/me/conversations/{uuid}' => 'auth',
    'POST api/v1/me/conversations/{uuid}/block' => 'auth',
    'DELETE api/v1/me/conversations/{uuid}/block' => 'auth',
    'POST api/v1/me/conversations/{uuid}/read' => 'auth',
    'GET api/v1/me/conversations/{uuid}/messages' => 'auth',
    'POST api/v1/me/conversations/{uuid}/messages' => 'auth',
    'POST api/v1/me/conversations/{uuid}/messages/{messageUuid}/report' => 'auth',
    // Événements : organisateur (voir aussi EventsAuthorizationTest).
    'GET api/v1/me/events' => 'auth',
    'POST api/v1/me/events' => 'auth',
    'GET api/v1/me/events/{uuid}' => 'auth',
    'PATCH api/v1/me/events/{uuid}' => 'auth',
    'DELETE api/v1/me/events/{uuid}' => 'auth',
    'POST api/v1/me/events/{uuid}/submit' => 'auth',
    'POST api/v1/me/events/{uuid}/withdraw' => 'auth',
    'DELETE api/v1/me/events/{uuid}/revision' => 'auth',
    'POST api/v1/me/events/{uuid}/cancel' => 'auth',
    'GET api/v1/me/events/{uuid}/registrations' => 'auth',
    'DELETE api/v1/me/events/{uuid}/registrations/{registrationUuid}' => 'auth',
    'GET api/v1/me/events/{uuid}/announcements' => 'auth',
    'POST api/v1/me/events/{uuid}/announcements' => 'auth',
    // Événements : inscriptions.
    'POST api/v1/events/{slug}/registration' => 'auth',
    'DELETE api/v1/events/{slug}/registration' => 'auth',
    'GET api/v1/me/event-registrations' => 'auth',
    'GET api/v1/me/event-registrations/{slug}' => 'auth',
    'GET api/v1/me/event-registrations/{slug}/calendar.ics' => 'auth',
    // Modération des événements : admin avec double authentification (EnsureModerator).
    'GET api/v1/admin/events/queue' => 'auth',
    'GET api/v1/admin/events/{uuid}' => 'auth',
    'POST api/v1/admin/events/{uuid}/approve' => 'auth',
    'POST api/v1/admin/events/{uuid}/reject' => 'auth',
    'POST api/v1/admin/events/{uuid}/cancel' => 'auth',
    'GET api/v1/admin/event-revisions/{uuid}' => 'auth',
    'POST api/v1/admin/event-revisions/{uuid}/approve' => 'auth',
    'POST api/v1/admin/event-revisions/{uuid}/reject' => 'auth',
    'GET api/v1/admin/event-announcements/{uuid}' => 'auth',
    'POST api/v1/admin/event-announcements/{uuid}/approve' => 'auth',
    'POST api/v1/admin/event-announcements/{uuid}/reject' => 'auth',
];

/**
 * Remplace les paramètres d'une route par des valeurs valides syntaxiquement.
 */
function concreteUri(string $uri): string
{
    return '/'.strtr($uri, [
        '{uuid}' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
        '{messageUuid}' => '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed',
        '{registrationUuid}' => '6ec0bd7f-11c0-43da-975e-2a8ad9ebae0b',
        '{slug}' => 'un-prestataire',
        '{id}' => '1',
        '{hash}' => str_repeat('a', 40),
    ]);
}

it('classe chaque route de l\'API comme publique ou authentifiée', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/'))
        ->flatMap(fn ($route): array => array_map(
            fn (string $method): string => "{$method} {$route->uri()}",
            array_diff($route->methods(), ['HEAD']),
        ))
        ->sort()
        ->values()
        ->all();

    expect($routes)->toBe(collect(array_keys(API_ROUTES))->sort()->values()->all());
});

it('refuse chaque route authentifiée à un visiteur anonyme (401)', function (string $route): void {
    [$method, $uri] = explode(' ', $route, 2);

    $this->json($method, concreteUri($uri))->assertUnauthorized();
})->with(array_keys(array_filter(API_ROUTES, fn (string $access): bool => $access === 'auth')));

it('ouvre chaque route publique à un visiteur anonyme', function (string $route): void {
    [$method, $uri] = explode(' ', $route, 2);

    expect($this->json($method, concreteUri($uri))->status())->not->toBeIn([401, 403]);
})->with(array_keys(array_filter(API_ROUTES, fn (string $access): bool => $access === 'public')));

describe('BFLA : fonctions réservées à un rôle', function (): void {
    it('cache les projets derrière un 404 pour les rôles qui ne gèrent pas de projets', function (string $role): void {
        $project = Project::factory()->published()->create();
        $user = projectOwner($role);

        $this->actingAs($user)->getJson("/api/v1/me/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($user)->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => 'Piraté par un autre rôle'])->assertNotFound();
        $this->actingAs($user)->deleteJson("/api/v1/me/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/me/projects/{$project->uuid}/publish")->assertNotFound();
        $this->actingAs($user)->postJson("/api/v1/me/projects/{$project->uuid}/close")->assertNotFound();

        expect($project->fresh()->status)->toBe(ProjectStatus::Published)
            ->and($project->fresh()->title)->not->toBe('Piraté par un autre rôle');
    })->with(['prestataire', 'redacteur', 'admin']);

    it('réserve le profil prestataire au rôle prestataire, y compris pour l\'admin', function (string $role): void {
        $user = projectOwner($role);

        $this->actingAs($user)->getJson('/api/v1/me/provider-profile')->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/me/provider-profile', ['display_name' => 'Faux prestataire'])->assertForbidden();

        expect(ProviderProfile::query()->count())->toBe(0);
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);
});

describe('assignation de propriétés non autorisées', function (): void {
    it('ignore le propriétaire, le statut, les dates et l\'uuid envoyés à la création d\'un projet', function (): void {
        serviceCategory('site-vitrine');
        $owner = projectOwner('particulier');
        $victim = projectOwner('particulier');

        $uuid = $this->actingAs($owner)->postJson('/api/v1/me/projects', projectPayload([
            'user_id' => $victim->id,
            'status' => 'published',
            'published_at' => '2026-01-01T00:00:00Z',
            'expires_at' => '2030-01-01T00:00:00Z',
            'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
            'service_category_id' => 999,
        ]))->assertCreated()->json('data.uuid');

        $project = Project::query()->where('uuid', $uuid)->sole();
        expect($uuid)->not->toBe('9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            ->and($project->user_id)->toBe($owner->id)
            ->and($project->status)->toBe(ProjectStatus::Draft)
            ->and($project->published_at)->toBeNull()
            ->and($project->expires_at)->toBeNull();
    });

    it('ignore le propriétaire, la date de publication et le logo envoyés dans le profil prestataire', function (): void {
        $provider = projectOwner('prestataire');
        $victim = projectOwner('prestataire');

        $this->actingAs($provider)->putJson('/api/v1/me/provider-profile', [
            'display_name' => 'Mon studio',
            'user_id' => $victim->id,
            'published_at' => '2026-01-01T00:00:00Z',
            'logo_media_id' => 1,
            'id' => 999,
        ])->assertCreated();

        $profile = ProviderProfile::query()->sole();
        expect($profile->user_id)->toBe($provider->id)
            ->and($profile->published_at)->toBeNull()
            ->and($profile->logo_media_id)->toBeNull()
            ->and($profile->id)->not->toBe(999);
    });

    it('ignore la vérification de l\'email, les secrets 2FA et l\'avatar interne envoyés dans PATCH /me', function (): void {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->patchJson('/api/v1/me', [
            'name' => 'Camille',
            'email_verified_at' => '2026-01-01 00:00:00',
            'two_factor_secret' => 'SECRET',
            'two_factor_confirmed_at' => '2026-01-01 00:00:00',
            'avatar_media_id' => 1,
            'remember_token' => 'jeton',
        ])->assertOk();

        $user->refresh();
        expect($user->name)->toBe('Camille')
            ->and($user->hasVerifiedEmail())->toBeFalse()
            ->and($user->two_factor_secret)->toBeNull()
            ->and($user->two_factor_confirmed_at)->toBeNull()
            ->and($user->avatar_media_id)->toBeNull()
            ->and($user->remember_token)->not->toBe('jeton');
    });

    it('n\'attribue jamais le rôle demandé dans l\'inscription au-delà du type de compte', function (): void {
        Notification::fake();

        $this->postJson('/api/v1/auth/register', registrationPayload(['roles' => ['admin'], 'role' => 'admin']))
            ->assertStatus(202);

        expect(User::query()->sole()->getRoleNames()->all())->toBe(['particulier']);
    });
});
