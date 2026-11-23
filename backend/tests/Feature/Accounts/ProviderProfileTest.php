<?php

use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Support\Carbon;

function provider(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole('prestataire');

    return $user;
}

function profilePayload(array $overrides = []): array
{
    return array_merge([
        'display_name' => 'Électricité Dupont',
        'headline' => 'Électricien à Lyon depuis 15 ans',
        'bio' => "Installation, dépannage et mise aux normes.\nDevis gratuit.",
        'city' => 'Lyon',
        'country' => 'fr',
        'website' => 'https://electricite-dupont.example',
        'categories' => ['travaux-electricite'],
    ], $overrides);
}

beforeEach(function (): void {
    serviceCategory('travaux-electricite', 'Électricité', 'Electrical work');
});

describe('autorisation', function (): void {
    it('refuse l\'accès à un utilisateur sans le rôle prestataire', function (string $role): void {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->getJson('/api/v1/me/provider-profile')->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/me/provider-profile', profilePayload())->assertForbidden();

        expect(ProviderProfile::query()->count())->toBe(0);
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);

    it('renvoie 401 sans authentification', function (): void {
        $this->getJson('/api/v1/me/provider-profile')->assertUnauthorized();
        $this->putJson('/api/v1/me/provider-profile', profilePayload())->assertUnauthorized();
    });
});

describe('GET /me/provider-profile', function (): void {
    it('renvoie 404 tant que le prestataire n\'a pas de profil', function (): void {
        $this->actingAs(provider())->getJson('/api/v1/me/provider-profile')->assertNotFound();
    });

    it('renvoie le profil du prestataire connecté uniquement', function (): void {
        $user = provider();
        ProviderProfile::factory()->for($user)->create(['display_name' => 'Mon profil']);
        ProviderProfile::factory()->create(['display_name' => 'Le profil d\'un autre']);

        $this->actingAs($user)
            ->getJson('/api/v1/me/provider-profile')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Mon profil')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.user_id');
    });
});

describe('PUT /me/provider-profile', function (): void {
    it('crée le profil en brouillon puis le met à jour', function (): void {
        $user = provider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload())
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Électricité Dupont')
            ->assertJsonPath('data.slug', 'electricite-dupont')
            ->assertJsonPath('data.country', 'FR')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null);

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['headline' => 'Nouvelle accroche', 'website' => null]))
            ->assertOk()
            ->assertJsonPath('data.headline', 'Nouvelle accroche')
            ->assertJsonPath('data.website', null);

        expect(ProviderProfile::query()->where('user_id', $user->id)->count())->toBe(1);
    });

    it('remet à null les champs facultatifs absents', function (): void {
        $user = provider();
        ProviderProfile::factory()->for($user)->create(['city' => 'Lyon', 'bio' => 'Bio']);

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', ['display_name' => 'Dupont'])
            ->assertOk()
            ->assertJsonPath('data.city', null)
            ->assertJsonPath('data.bio', null);
    });

    it('garde le slug stable quand le nom affiché change', function (): void {
        $user = provider();

        $this->actingAs($user)->putJson('/api/v1/me/provider-profile', profilePayload())->assertCreated();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['display_name' => 'Dupont & Fils']))
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Dupont & Fils')
            ->assertJsonPath('data.slug', 'electricite-dupont');
    });

    it('ignore un slug envoyé par le client', function (): void {
        $user = provider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['slug' => 'slug-choisi']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'electricite-dupont');
    });

    it('rend le slug unique', function (): void {
        ProviderProfile::factory()->create(['slug' => 'electricite-dupont']);
        ProviderProfile::factory()->create(['slug' => 'electricite-dupont-2']);

        $this->actingAs(provider())
            ->putJson('/api/v1/me/provider-profile', profilePayload())
            ->assertCreated()
            ->assertJsonPath('data.slug', 'electricite-dupont-3');
    });

    it('utilise un slug de repli quand le nom ne contient aucun caractère latin', function (): void {
        $this->actingAs(provider())
            ->putJson('/api/v1/me/provider-profile', profilePayload(['display_name' => '★★']))
            ->assertCreated()
            ->assertJsonPath('data.slug', 'prestataire');
    });

    it('refuse la publication sans email vérifié', function (): void {
        $user = provider();
        $user->forceFill(['email_verified_at' => null])->save();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['status' => 'published']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'vérifier votre adresse email']);

        expect(ProviderProfile::query()->count())->toBe(0);
    });

    it('publie le profil avec un email vérifié, puis le repasse en brouillon', function (): void {
        Carbon::setTestNow('2026-11-10 09:00:00');
        $user = provider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['status' => 'published']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', '2026-11-10T09:00:00Z');

        // Une mise à jour sans statut conserve la publication et sa date.
        Carbon::setTestNow('2026-11-11 09:00:00');
        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['headline' => 'Autre accroche']))
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', '2026-11-10T09:00:00Z');

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['status' => 'draft']))
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.published_at', null);

        expect(ProviderProfile::query()->sole()->status)->toBe(ProviderProfileStatus::Draft);
    });

    it('refuse le HTML dans les champs texte', function (string $field, string $value): void {
        $this->actingAs(provider())
            ->putJson('/api/v1/me/provider-profile', profilePayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'script dans la bio' => ['bio', 'Bonjour <script>alert(1)</script>'],
        'balise fermante dans la bio' => ['bio', 'Texte</p>'],
        'commentaire dans la bio' => ['bio', '<!-- caché -->'],
        'lien dans l\'accroche' => ['headline', '<a href="https://x.example">Promo</a>'],
        'image dans le nom' => ['display_name', '<img src=x onerror=alert(1)>'],
        'balise dans la ville' => ['city', '<b>Lyon</b>'],
    ]);

    it('accepte les chevrons qui ne forment pas de balise', function (): void {
        $this->actingAs(provider())
            ->putJson('/api/v1/me/provider-profile', profilePayload(['bio' => 'Tarifs : < 50 € la visite, 3 > 2 et <3']))
            ->assertCreated()
            ->assertJsonPath('data.bio', 'Tarifs : < 50 € la visite, 3 > 2 et <3');
    });

    it('refuse les entrées invalides', function (string $field, mixed $value): void {
        $this->actingAs(provider())
            ->putJson('/api/v1/me/provider-profile', profilePayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'nom absent' => ['display_name', null],
        'nom trop long' => ['display_name', str_repeat('a', 101)],
        'accroche trop longue' => ['headline', str_repeat('a', 161)],
        'bio trop longue' => ['bio', str_repeat('a', 5001)],
        'ville trop longue' => ['city', str_repeat('a', 101)],
        'pays inconnu' => ['country', 'XX'],
        'site sans schéma' => ['website', 'electricite-dupont.example'],
        'site en ftp' => ['website', 'ftp://electricite-dupont.example'],
        'site en javascript' => ['website', 'javascript:alert(1)'],
        'site trop long' => ['website', 'https://example.com/'.str_repeat('a', 240)],
        'statut inconnu' => ['status', 'archived'],
    ]);
});

describe('GET /providers/{slug}', function (): void {
    it('renvoie 404 tant que le profil est en brouillon', function (): void {
        ProviderProfile::factory()->create(['slug' => 'electricite-dupont']);

        $this->getJson('/api/v1/providers/electricite-dupont')->assertNotFound();
    });

    it('renvoie 404 pour un slug inconnu sans révéler le modèle', function (): void {
        // Comme en production : sans trace de débogage.
        config(['app.debug' => false]);

        $this->getJson('/api/v1/providers/inconnu')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Prestataire introuvable.']);
    });

    it('affiche le profil une fois publié, sans authentification', function (): void {
        $user = provider();

        $this->actingAs($user)
            ->putJson('/api/v1/me/provider-profile', profilePayload(['status' => 'published']))
            ->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->assertGuest();

        $this->getJson('/api/v1/providers/electricite-dupont')
            ->assertOk()
            ->assertJsonPath('data.display_name', 'Électricité Dupont')
            ->assertJsonPath('data.city', 'Lyon');
    });

    it('n\'expose aucune donnée personnelle ni identifiant interne', function (): void {
        $user = provider(['email' => 'secret@example.com', 'name' => 'Jean Secret']);
        ProviderProfile::factory()->for($user)->published()->create(['slug' => 'electricite-dupont']);

        $response = $this->getJson('/api/v1/providers/electricite-dupont')->assertOk();

        expect(array_keys($response->json('data')))->toEqualCanonicalizing([
            'display_name', 'slug', 'headline', 'bio', 'city', 'country', 'website', 'logo_url', 'banner_url', 'categories', 'published_at',
        ]);
        expect($response->getContent())
            ->not->toContain('secret@example.com')
            ->not->toContain('Jean Secret')
            ->not->toContain('"id"')
            ->not->toContain('user_id')
            ->not->toContain('status');
    });
});

it('supprime le profil prestataire avec le compte', function (): void {
    $user = provider();
    $profile = ProviderProfile::factory()->for($user)->published()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/me', ['password' => 'password'])
        ->assertNoContent();

    $this->assertModelMissing($user);
    $this->assertModelMissing($profile);
});
