<?php

use App\Http\Middleware\EnsureModerator;
use App\Models\User;
use App\Support\TwoFactorSession;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;

/*
| Double authentification obligatoire pour l'équipe (admin, rédacteur ; décision 17) :
| aucune fonction d'équipe sans 2FA confirmée et session ouverte avec un code, aucune
| désactivation de la 2FA, comptes de démonstration de l'équipe utilisables en local.
*/

function teamMemberWithoutTwoFactor(string $role = 'admin'): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('protège chaque route d\'administration par EnsureModerator', function (): void {
    $unprotected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/'))
        ->reject(fn ($route): bool => collect($route->gatherMiddleware())->contains(fn (mixed $m): bool => is_string($m) && str_starts_with($m, EnsureModerator::class)))
        ->map(fn ($route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($unprotected)->toBe([]);
});

it('refuse chaque route d\'administration à un admin sans 2FA, avec un code pour le frontend', function (): void {
    $admin = teamMemberWithoutTwoFactor();
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/admin/') && in_array('GET', $route->methods(), true));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $uri = preg_replace('/\{[^}]+\}/', '3f2504e0-4f89-41d3-9a0c-0305e82c3301', $route->uri());

        fromFrontend()->actingAs($admin)->withSession([TwoFactorSession::KEY => time()])
            ->getJson('/'.$uri)
            ->assertForbidden()
            ->assertJsonPath('code', 'two_factor_required')
            ->assertJsonPath('reason', 'not_enabled');
    }
});

it('demande de se reconnecter avec un code quand la session ne l\'a pas présenté', function (): void {
    fromFrontend()->actingAs(moderator())
        ->getJson('/api/v1/admin/events/queue')
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required')
        ->assertJsonPath('reason', 'session');
});

it('refuse la modération du Blog à un rédacteur sans 2FA', function (): void {
    fromFrontend()->actingAs(teamMemberWithoutTwoFactor('redacteur'))->withSession([TwoFactorSession::KEY => time()])
        ->getJson('/api/v1/admin/blog/queue')
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');
});

it('interdit à l\'équipe de désactiver sa 2FA, et le journalise', function (string $role): void {
    $member = userWithTwoFactor();
    $member->assignRole($role);

    $this->actingAs($member)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson('/api/v1/auth/user/two-factor-authentication')
        ->assertForbidden()
        ->assertJsonPath('code', 'two_factor_required');

    expect($member->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(securityLog('auth.two_factor_disable_denied'))->toHaveCount(1);
})->with(['admin', 'redacteur']);

it('laisse les autres comptes désactiver leur 2FA', function (string $role): void {
    $user = userWithTwoFactor();
    $user->assignRole($role);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->deleteJson('/api/v1/auth/user/two-factor-authentication')
        ->assertSuccessful();

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
})->with(['particulier', 'prestataire', 'entrepreneur']);

it('annonce dans GET /me que la 2FA est obligatoire pour l\'équipe seulement', function (string $role, bool $required): void {
    $this->actingAs(teamMemberWithoutTwoFactor($role))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.two_factor_required', $required);
})->with([
    ['admin', true],
    ['redacteur', true],
    ['particulier', false],
    ['prestataire', false],
    ['entrepreneur', false],
]);

it('active la 2FA du rédacteur de démonstration avec un secret documenté, utilisable pour modérer', function (): void {
    allowDemoSeeding();
    $this->seed(DatabaseSeeder::class);
    $redacteur = User::query()->where('email', 'redacteur@example.com')->sole();

    expect($redacteur->two_factor_confirmed_at)->not->toBeNull()
        ->and(decrypt($redacteur->two_factor_secret))->toBe(DatabaseSeeder::DEMO_REDACTEUR_TWO_FACTOR_SECRET);

    app()->detectEnvironment(fn (): string => 'testing');
    $this->postJson('/api/v1/auth/login', ['email' => 'redacteur@example.com', 'password' => DatabaseSeeder::LOCAL_PASSWORD])
        ->assertOk()
        ->assertJsonPath('two_factor', true);
    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => app(Google2FA::class)->getCurrentOtp(DatabaseSeeder::DEMO_REDACTEUR_TWO_FACTOR_SECRET)])
        ->assertSuccessful();

    fromFrontend()->getJson('/api/v1/admin/blog/queue')->assertOk();
});
