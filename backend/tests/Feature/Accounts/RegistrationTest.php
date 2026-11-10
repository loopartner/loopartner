<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

it('inscrit un utilisateur avec le rôle de son type de compte', function (string $accountType): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['account_type' => $accountType]))
        ->assertStatus(202);

    $user = User::query()->where('email', 'camille@example.com')->firstOrFail();

    expect($user->getRoleNames()->all())->toBe([$accountType]);
})->with(['particulier', 'prestataire', 'entrepreneur']);

it('refuse un type de compte non autorisé à l\'inscription', function (string $accountType): void {
    $this->postJson('/api/v1/auth/register', registrationPayload(['account_type' => $accountType]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_type']);

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
})->with(['redacteur', 'admin', 'superuser', '']);

it('refuse une inscription sans type de compte', function (): void {
    $payload = registrationPayload();
    unset($payload['account_type']);

    $this->postJson('/api/v1/auth/register', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account_type']);

    expect(User::query()->count())->toBe(0);
});
