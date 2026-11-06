<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

const VALID_PASSWORD = 'correct-horse-battery-staple';

it('inscrit un utilisateur puis le connecte', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Camille Martin',
        'email' => 'camille@example.com',
        'password' => VALID_PASSWORD,
        'password_confirmation' => VALID_PASSWORD,
    ])->assertCreated();

    $user = User::query()->where('email', 'camille@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->assertGuest();

    $this->postJson('/api/v1/auth/login', [
        'email' => 'camille@example.com',
        'password' => VALID_PASSWORD,
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('refuse un mot de passe de moins de 12 caractères', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Camille Martin',
        'email' => 'camille@example.com',
        'password' => 'Court-123',
        'password_confirmation' => 'Court-123',
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    $this->assertGuest();
});

it('refuse un mot de passe présent dans une fuite connue', function (): void {
    fakeCompromisedPassword(VALID_PASSWORD);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Camille Martin',
        'email' => 'camille@example.com',
        'password' => VALID_PASSWORD,
        'password_confirmation' => VALID_PASSWORD,
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    expect(User::query()->count())->toBe(0);
});

it('bloque la connexion après 5 tentatives échouées en une minute', function (): void {
    User::factory()->create(['email' => 'camille@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'camille@example.com',
            'password' => 'mauvais-mot-de-passe',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'camille@example.com',
        'password' => 'mauvais-mot-de-passe',
    ])->assertTooManyRequests();

    $this->assertGuest();
});
