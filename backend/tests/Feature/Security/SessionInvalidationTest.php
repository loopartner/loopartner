<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/*
| La réinitialisation et le changement du mot de passe déconnectent les autres sessions de
| l'utilisateur : sessions en base supprimées, et sessions encore actives refusées par
| AuthenticateSession (empreinte du mot de passe en session).
*/

beforeEach(function (): void {
    Notification::fake();
    config(['session.driver' => 'database', 'sanctum.stateful' => ['localhost:3000']]);
});

/**
 * Enregistre une session « sur un autre appareil » pour l'utilisateur, comme le fait le driver database.
 */
function otherDeviceSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '198.51.100.20',
        'user_agent' => 'Autre appareil',
        'payload' => base64_encode(json_encode([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

/**
 * @return list<string>
 */
function sessionIdsOf(User $user): array
{
    return DB::table('sessions')->where('user_id', $user->id)->orderBy('id')->pluck('id')->all();
}

it('supprime toutes les sessions de l\'utilisateur après une réinitialisation du mot de passe', function (): void {
    $user = User::factory()->create(['email' => 'camille@example.com']);
    $someoneElse = User::factory()->create();
    otherDeviceSession($user, 'session-telephone');
    otherDeviceSession($user, 'session-ordinateur');
    otherDeviceSession($someoneElse, 'session-autre-utilisateur');

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => 'camille@example.com',
        'password' => 'nouveau-mot-de-passe-solide',
        'password_confirmation' => 'nouveau-mot-de-passe-solide',
    ])->assertOk();

    expect(sessionIdsOf($user))->toBe([])
        ->and(sessionIdsOf($someoneElse))->toBe(['session-autre-utilisateur']);
});

it('refuse une session encore ouverte avec l\'ancien mot de passe après une réinitialisation', function (): void {
    $user = User::factory()->create(['email' => 'camille@example.com']);
    $oldPasswordHash = Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword());

    // Avant la réinitialisation, la session du frontend fonctionne.
    $this->withHeader('Referer', 'http://localhost:3000/')
        ->withSession(['password_hash_web' => $oldPasswordHash])
        ->actingAs($user)
        ->getJson('/api/v1/me')
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => Password::broker()->createToken($user),
        'email' => 'camille@example.com',
        'password' => 'nouveau-mot-de-passe-solide',
        'password_confirmation' => 'nouveau-mot-de-passe-solide',
    ])->assertOk();

    $this->app['auth']->forgetGuards();

    $this->withHeader('Referer', 'http://localhost:3000/')
        ->withSession(['password_hash_web' => $oldPasswordHash])
        ->actingAs($user->fresh())
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('supprime les autres sessions après un changement de mot de passe, mais garde la session courante', function (): void {
    $user = User::factory()->create(['password' => 'ancien-mot-de-passe-solide']);
    otherDeviceSession($user, 'session-telephone');

    $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
        'current_password' => 'ancien-mot-de-passe-solide',
        'password' => 'nouveau-mot-de-passe-solide',
        'password_confirmation' => 'nouveau-mot-de-passe-solide',
    ])->assertOk();

    // Seule reste la session de la requête, enregistrée par le driver database en fin de requête.
    expect(sessionIdsOf($user))->not->toContain('session-telephone')
        ->and(sessionIdsOf($user))->toHaveCount(1);
});
