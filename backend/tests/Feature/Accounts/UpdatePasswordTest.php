<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

const NEW_PASSWORD = 'nouvelle-phrase-de-passe-solide';

function passwordPayload(array $overrides = []): array
{
    return array_merge([
        'current_password' => 'password',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ], $overrides);
}

it('change le mot de passe avec le mot de passe actuel', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/user/password', passwordPayload())
        ->assertOk();

    expect(Hash::check(NEW_PASSWORD, $user->fresh()->password))->toBeTrue();
});

it('refuse le changement sans le mot de passe actuel', function (): void {
    $user = User::factory()->create();
    $payload = passwordPayload();
    unset($payload['current_password']);

    $this->actingAs($user)
        ->putJson('/api/v1/auth/user/password', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuse le changement avec un mot de passe actuel incorrect', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/user/password', passwordPayload(['current_password' => 'mauvais-mot-de-passe']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['current_password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuse un nouveau mot de passe de moins de 12 caractères', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/user/password', passwordPayload([
            'password' => 'Court-123',
            'password_confirmation' => 'Court-123',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuse un nouveau mot de passe compromis', function (): void {
    fakeCompromisedPassword(NEW_PASSWORD);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/user/password', passwordPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);

    expect(Hash::check('password', $user->fresh()->password))->toBeTrue();
});

it('refuse un nouveau mot de passe non confirmé', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->putJson('/api/v1/auth/user/password', passwordPayload(['password_confirmation' => 'autre-chose-encore']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password']);
});

it('renvoie 401 sans authentification', function (): void {
    $this->putJson('/api/v1/auth/user/password', passwordPayload())->assertUnauthorized();
});
