<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

describe('GET /me', function (): void {
    it('renvoie l\'utilisateur connecté avec ses rôles et ses préférences', function (): void {
        $user = User::factory()->create([
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'locale' => 'en',
            'timezone' => 'Europe/Paris',
            'country' => 'FR',
        ]);
        $user->assignRole('prestataire');

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertExactJson([
                'data' => [
                    'name' => 'Camille Martin',
                    'email' => 'camille@example.com',
                    'roles' => ['prestataire'],
                    'locale' => 'en',
                    'timezone' => 'Europe/Paris',
                    'country' => 'FR',
                    'avatar_url' => null,
                    'email_verified' => true,
                    'two_factor_enabled' => false,
                    'premium' => ['active' => false, 'source' => null, 'starts_at' => null, 'ends_at' => null],
                ],
            ]);
    });

    it('signale un email non vérifié et une double authentification active', function (): void {
        $user = User::factory()->unverified()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $this->actingAs($user)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.two_factor_enabled', true);
    });

    it('n\'expose aucune donnée sensible', function (): void {
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code-1', 'code-2'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

        foreach (['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $key) {
            $response->assertJsonMissingPath("data.{$key}");
        }

        expect($response->getContent())
            ->not->toContain((string) $user->password)
            ->not->toContain((string) $user->two_factor_secret);
    });

    it('renvoie 401 sans authentification', function (): void {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    });
});

describe('PATCH /me', function (): void {
    it('met à jour le nom et les préférences', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me', [
                'name' => 'Nouveau Nom',
                'locale' => 'en',
                'timezone' => 'America/Toronto',
                'country' => 'ca',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nouveau Nom')
            ->assertJsonPath('data.locale', 'en')
            ->assertJsonPath('data.timezone', 'America/Toronto')
            ->assertJsonPath('data.country', 'CA');

        expect($user->fresh())
            ->name->toBe('Nouveau Nom')
            ->locale->toBe('en')
            ->timezone->toBe('America/Toronto')
            ->country->toBe('CA');
    });

    it('refuse les alias de fuseau horaire non canoniques', function (): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me', ['timezone' => 'America/Montreal'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['timezone']);
    });

    it('accepte une mise à jour partielle et la suppression du pays', function (): void {
        $user = User::factory()->create(['name' => 'Camille', 'country' => 'FR', 'locale' => 'en']);

        $this->actingAs($user)
            ->patchJson('/api/v1/me', ['country' => null])
            ->assertOk();

        expect($user->fresh())
            ->name->toBe('Camille')
            ->locale->toBe('en')
            ->country->toBeNull();
    });

    it('refuse une préférence invalide', function (string $field, mixed $value): void {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/v1/me', [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'locale inconnue' => ['locale', 'de'],
        'locale vide' => ['locale', ''],
        'fuseau inconnu' => ['timezone', 'Mars/Olympus'],
        'fuseau non IANA' => ['timezone', '+02:00'],
        'pays inconnu' => ['country', 'XX'],
        'pays alpha-3' => ['country', 'FRA'],
        'nom trop long' => ['name', str_repeat('a', 256)],
    ]);

    it('refuse de modifier l\'email, les rôles ou le mot de passe', function (string $field, mixed $value): void {
        $user = User::factory()->create(['email' => 'camille@example.com']);
        $user->assignRole('particulier');
        $passwordHash = $user->password;

        $this->actingAs($user)
            ->patchJson('/api/v1/me', ['name' => 'Autre', $field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $user->refresh();
        expect($user->email)->toBe('camille@example.com')
            ->and($user->password)->toBe($passwordHash)
            ->and($user->name)->not->toBe('Autre')
            ->and($user->getRoleNames()->all())->toBe(['particulier']);
    })->with([
        'email' => ['email', 'pirate@example.com'],
        'rôles' => ['roles', ['admin']],
        'type de compte' => ['account_type', 'prestataire'],
        'mot de passe' => ['password', 'un-nouveau-mot-de-passe'],
    ]);

    it('renvoie 401 sans authentification', function (): void {
        $this->patchJson('/api/v1/me', ['name' => 'Anonyme'])->assertUnauthorized();
    });
});

it('enregistre les préférences facultatives à l\'inscription', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload([
        'locale' => 'en',
        'timezone' => 'Europe/Brussels',
        'country' => 'be',
    ]))->assertStatus(202);

    expect(User::query()->where('email', 'camille@example.com')->firstOrFail())
        ->locale->toBe('en')
        ->timezone->toBe('Europe/Brussels')
        ->country->toBe('BE');
});

it('applique les préférences par défaut à l\'inscription', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

    expect(User::query()->where('email', 'camille@example.com')->firstOrFail())
        ->locale->toBe('fr')
        ->timezone->toBe('UTC')
        ->country->toBeNull();
});

it('refuse des préférences invalides à l\'inscription', function (): void {
    $this->postJson('/api/v1/auth/register', registrationPayload([
        'locale' => 'es',
        'timezone' => 'Nowhere/City',
        'country' => 'ZZ',
    ]))->assertUnprocessable()->assertJsonValidationErrors(['locale', 'timezone', 'country']);

    expect(User::query()->count())->toBe(0);
});
