<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

/*
| Limites de débit des routes d'authentification (groupe "web" de Fortify, hors throttle:api).
| Chaque test envoie exactement la limite configurée, puis vérifie le 429 à la requête suivante.
*/

beforeEach(function (): void {
    Notification::fake();
});

/**
 * Valeur d'une limite de config/security.php.
 */
function rateLimit(string $key): int
{
    return (int) config('security.rate_limits.'.$key);
}

/**
 * Envoie $times requêtes depuis l'IP donnée et renvoie la dernière réponse.
 */
function hitFrom(string $ip, int $times, Closure $request): mixed
{
    $response = null;

    for ($i = 0; $i < $times; $i++) {
        $response = $request(test()->withServerVariables(['REMOTE_ADDR' => $ip]));
    }

    return $response;
}

it('applique les limites validées par défaut', function (): void {
    expect(config('security.rate_limits'))->toMatchArray([
        'login' => ['per_minute' => 5, 'per_hour_per_email' => 30],
        'two_factor' => ['per_minute' => 5, 'per_hour' => 20],
        'register' => ['per_hour_per_ip' => 30, 'per_hour_per_email' => 5],
        'forgot_password' => ['per_hour_per_email' => 5, 'per_hour_per_ip' => 20],
        'reset_password' => ['per_hour_per_ip' => 10],
        'password_update' => ['per_minute' => 5],
        'password_confirm' => ['per_minute' => 5],
        'verification' => ['per_minute' => 3, 'per_hour' => 10],
        'api' => ['per_minute' => 60],
        'media_uploads' => ['per_minute' => 20],
        'account_deletion' => ['per_minute' => 6],
    ]);
});

it('limite les inscriptions par IP et par heure', function (): void {
    $limit = rateLimit('register.per_hour_per_ip');

    // Des inscriptions invalides comptent aussi : la limite protège contre l'automatisation.
    hitFrom('10.0.0.1', $limit, fn ($test) => $test->postJson('/api/v1/auth/register', []))->assertUnprocessable();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/v1/auth/register', [])->assertTooManyRequests()->assertHeader('Retry-After');

    // Une autre IP n'est pas concernée.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/v1/auth/register', [])->assertUnprocessable();
});

it('limite les inscriptions par email, toutes IP confondues', function (): void {
    $limit = rateLimit('register.per_hour_per_email');

    for ($i = 0; $i < $limit; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.5.{$i}"])
            ->postJson('/api/v1/auth/register', registrationPayload())
            ->assertStatus(202);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.5.250'])
        ->postJson('/api/v1/auth/register', registrationPayload(['email' => 'CAMILLE@example.com']))
        ->assertTooManyRequests();
});

it('limite les demandes de réinitialisation par email', function (): void {
    $limit = rateLimit('forgot_password.per_hour_per_email');

    for ($i = 0; $i < $limit; $i++) {
        // Une IP différente à chaque fois : c'est l'email visé qui est limité.
        $status = $this->withServerVariables(['REMOTE_ADDR' => "10.0.1.{$i}"])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'victime@example.com'])
            ->status();

        expect($status)->not->toBe(429);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.250'])
        ->postJson('/api/v1/auth/forgot-password', ['email' => 'VICTIME@example.com'])
        ->assertTooManyRequests();
});

it('limite les demandes de réinitialisation par IP', function (): void {
    $limit = rateLimit('forgot_password.per_hour_per_ip');

    for ($i = 0; $i < $limit; $i++) {
        $status = $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => "personne{$i}@example.com"])
            ->status();

        expect($status)->not->toBe(429);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])
        ->postJson('/api/v1/auth/forgot-password', ['email' => 'encore@example.com'])
        ->assertTooManyRequests();
});

it('limite les réinitialisations par IP', function (): void {
    $limit = rateLimit('reset_password.per_hour_per_ip');

    hitFrom('10.0.3.1', $limit, fn ($test) => $test->postJson('/api/v1/auth/reset-password', [
        'token' => 'jeton-invalide',
        'email' => 'camille@example.com',
        'password' => 'correct-horse-battery-staple',
        'password_confirmation' => 'correct-horse-battery-staple',
    ]))->assertUnprocessable();

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.3.1'])
        ->postJson('/api/v1/auth/reset-password', [])->assertTooManyRequests();
});

it('limite les changements de mot de passe par utilisateur', function (): void {
    $user = User::factory()->create();
    $limit = rateLimit('password_update.per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->actingAs($user)->putJson('/api/v1/auth/user/password', [
            'current_password' => 'mauvais-mot-de-passe',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertUnprocessable();
    }

    $this->actingAs($user)->putJson('/api/v1/auth/user/password', [])->assertTooManyRequests();
});

it('limite les confirmations de mot de passe par utilisateur', function (): void {
    $user = User::factory()->create();
    $limit = rateLimit('password_confirm.per_minute');

    for ($i = 0; $i < $limit; $i++) {
        $this->actingAs($user)->postJson('/api/v1/auth/user/confirm-password', ['password' => 'mauvais-mot-de-passe'])
            ->assertUnprocessable();
    }

    $this->actingAs($user)->postJson('/api/v1/auth/user/confirm-password', ['password' => 'mauvais-mot-de-passe'])
        ->assertTooManyRequests();
});

it('limite les renvois du lien de vérification par minute et par heure', function (): void {
    $user = User::factory()->unverified()->create();
    $perMinute = rateLimit('verification.per_minute');
    $perHour = rateLimit('verification.per_hour');

    $sent = 0;
    while ($sent < $perHour) {
        for ($i = 0; $i < $perMinute && $sent < $perHour; $i++, $sent++) {
            $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertStatus(202);
        }

        if ($sent < $perHour) {
            $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertTooManyRequests();
            $this->travel(61)->seconds();
        }
    }

    $this->travel(61)->seconds();
    $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertTooManyRequests();
});

it('limite les tentatives de connexion par email, toutes IP confondues', function (): void {
    User::factory()->create(['email' => 'camille@example.com']);
    $limit = rateLimit('login.per_hour_per_email');

    for ($i = 0; $i < $limit; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => "10.0.4.{$i}"])
            ->postJson('/api/v1/auth/login', ['email' => 'camille@example.com', 'password' => 'mauvais-mot-de-passe'])
            ->assertUnprocessable();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.4.250'])
        ->postJson('/api/v1/auth/login', ['email' => 'Camille@example.com', 'password' => 'mauvais-mot-de-passe'])
        ->assertTooManyRequests();
});

it('limite les défis de double authentification par minute et par heure', function (): void {
    $user = userWithTwoFactor();
    $perMinute = rateLimit('two_factor.per_minute');
    $perHour = rateLimit('two_factor.per_hour');

    $attempts = 0;
    while ($attempts < $perHour) {
        for ($i = 0; $i < $perMinute && $attempts < $perHour; $i++, $attempts++) {
            $this->withSession(['login.id' => $user->id])
                ->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])
                ->assertUnprocessable();
        }

        if ($attempts < $perHour) {
            $this->withSession(['login.id' => $user->id])
                ->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])->assertTooManyRequests();
            $this->travel(61)->seconds();
        }
    }

    $this->travel(61)->seconds();
    $this->withSession(['login.id' => $user->id])
        ->postJson('/api/v1/auth/two-factor-challenge', ['code' => '000000'])->assertTooManyRequests();
});
