<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;

const VALID_PASSWORD = 'correct-horse-battery-staple';

it('inscrit un utilisateur sans le connecter, puis il se connecte', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Camille Martin',
        'account_type' => 'particulier',
        'email' => 'camille@example.com',
        'password' => VALID_PASSWORD,
        'password_confirmation' => VALID_PASSWORD,
    ])->assertStatus(202);

    $user = User::query()->where('email', 'camille@example.com')->firstOrFail();
    $this->assertGuest();
    Notification::assertSentTo($user, VerifyEmailNotification::class);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'camille@example.com',
        'password' => VALID_PASSWORD,
    ])->assertOk();

    $this->assertAuthenticatedAs($user);

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->assertGuest();
});

it('refuse un mot de passe de moins de 12 caractères', function (): void {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Camille Martin',
        'account_type' => 'particulier',
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
        'account_type' => 'particulier',
        'email' => 'camille@example.com',
        'password' => VALID_PASSWORD,
        'password_confirmation' => VALID_PASSWORD,
    ])->assertUnprocessable()->assertJsonValidationErrors(['password']);

    expect(User::query()->count())->toBe(0);
});
