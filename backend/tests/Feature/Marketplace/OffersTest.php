<?php

use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Support\Carbon;

/*
| Offres des prestataires (décisions 38 à 40) : gérées par leur auteur, publiées sans
| validation préalable, publiques avec la qualité déclarée du prestataire, prix indicatif TTC.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
});

function createdOffer(User $provider, array $overrides = []): Offer
{
    $uuid = test()->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload($overrides))->assertCreated()->json('data.uuid');

    return Offer::query()->where('uuid', $uuid)->sole();
}

describe('gestion par le prestataire', function (): void {
    it('réserve les offres aux prestataires (403)', function (string $role): void {
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user)->getJson('/api/v1/me/offers')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/me/offers', offerPayload())->assertForbidden();
    })->with(['particulier', 'entrepreneur', 'admin']);

    it('crée une offre en brouillon, sans slug ni publication', function (): void {
        $provider = marketplaceProvider();

        $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.slug', null)
            ->assertJsonPath('data.price_cents', 150000)
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.category.slug', 'sites-web');

        $this->actingAs($provider)->getJson('/api/v1/me/offers')->assertOk()->assertJsonCount(1, 'data');
    });

    it('refuse un contenu invalide et les champs fixés par la plateforme (422)', function (array $overrides, string $field): void {
        $this->actingAs(marketplaceProvider())->postJson('/api/v1/me/offers', offerPayload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        expect(Offer::query()->count())->toBe(0);
    })->with([
        'statut imposé' => [['status' => 'published'], 'status'],
        'slug imposé' => [['slug' => 'mon-offre'], 'slug'],
        'propriétaire imposé' => [['user_id' => 1], 'user_id'],
        'devise imposée' => [['currency' => 'USD'], 'currency'],
        'prix sur une offre sur devis' => [['pricing_mode' => 'on_quote', 'price_cents' => 100], 'price_cents'],
        'prix absent' => [['price_cents' => null], 'price_cents'],
        'prix décimal' => [['price_cents' => 12.5], 'price_cents'],
        'HTML' => [['summary' => '<b>Un site vitrine responsive de cinq pages, livré en trois semaines</b>'], 'summary'],
        'lien non sécurisé dans la description' => [['description' => 'Voir mes réalisations sur http://exemple-de-portfolio.com avant de commander.'], 'description'],
        'catégorie inconnue' => [['category' => 'inconnue'], 'category'],
        'titre trop court' => [['title' => 'Site'], 'title'],
    ]);

    it('cache l\'offre d\'un autre prestataire derrière un 404', function (): void {
        $offer = createdOffer(marketplaceProvider());
        $other = marketplaceProvider();

        $this->actingAs($other)->getJson("/api/v1/me/offers/{$offer->uuid}")->assertNotFound();
        $this->actingAs($other)->patchJson("/api/v1/me/offers/{$offer->uuid}", ['title' => 'Offre détournée par un autre'])->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")->assertNotFound();
        $this->actingAs($other)->postJson("/api/v1/me/offers/{$offer->uuid}/unpublish")->assertNotFound();
        $this->actingAs($other)->deleteJson("/api/v1/me/offers/{$offer->uuid}")->assertNotFound();

        expect($offer->fresh()->title)->toBe('Création de site vitrine');
    });

    it('limite le nombre total d\'offres (409)', function (): void {
        config(['marketplace.offers.max_total' => 1]);
        $provider = marketplaceProvider();
        createdOffer($provider);

        $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload())->assertConflict();
    });

    it('modifie une offre publiée directement et passe en « sur devis » sans prix', function (): void {
        $provider = marketplaceProvider();
        $offer = publishedOfferOf($provider);

        $this->actingAs($provider)->patchJson("/api/v1/me/offers/{$offer->uuid}", ['pricing_mode' => 'on_quote'])
            ->assertOk()
            ->assertJsonPath('data.price_cents', null)
            ->assertJsonPath('data.version', 2);

        $this->getJson("/api/v1/offers/{$offer->slug}")->assertOk()->assertJsonPath('data.pricing.mode', 'on_quote');

        $this->actingAs($provider)->patchJson("/api/v1/me/offers/{$offer->uuid}", ['pricing_mode' => 'fixed'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price_cents');
    });

    it('supprime une offre', function (): void {
        $provider = marketplaceProvider();
        $offer = publishedOfferOf($provider);

        $this->actingAs($provider)->deleteJson("/api/v1/me/offers/{$offer->uuid}")->assertNoContent();

        expect(Offer::query()->count())->toBe(0)
            ->and(securityLog('marketplace.offer_deleted'))->toHaveCount(1);
    });

    it('limite les écritures (429)', function (): void {
        config(['security.rate_limits.offer_writes.per_hour' => 1]);
        $provider = marketplaceProvider();

        $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload())->assertCreated();
        $this->actingAs($provider)->postJson('/api/v1/me/offers', offerPayload())->assertTooManyRequests();
    });
});

describe('publication', function (): void {
    it('publie avec un slug stable, dépublie puis republie', function (): void {
        $provider = marketplaceProvider();
        $offer = createdOffer($provider);

        $slug = $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->json('data.slug');

        expect($slug)->toStartWith('creation-de-site-vitrine-');
        $this->getJson("/api/v1/offers/{$slug}")->assertOk();
        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")->assertConflict();

        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/unpublish")->assertOk()->assertJsonPath('data.status', 'unpublished');
        $this->getJson("/api/v1/offers/{$slug}")->assertNotFound();
        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/unpublish")->assertConflict();

        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")->assertOk()->assertJsonPath('data.slug', $slug);

        expect(securityLog('marketplace.offer_published'))->toHaveCount(2)
            ->and(json_encode(securityLog('marketplace.offer_published')[0]->context))->not->toContain('vitrine');
    });

    it('exige une identité professionnelle complète (409 seller_profile_incomplete)', function (): void {
        $provider = browsingProvider();
        $offer = createdOffer($provider);

        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")
            ->assertConflict()
            ->assertJsonPath('code', 'seller_profile_incomplete')
            ->assertJsonPath('missing', ['seller_profile']);

        SellerProfile::factory()->create(['user_id' => $provider->id, 'self_certification_version' => '2020-01-01']);

        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")
            ->assertConflict()
            ->assertJsonPath('missing', ['self_certification']);

        expect($offer->fresh()->status)->toBe(OfferStatus::Draft);
    });

    it('exige un email vérifié et un profil prestataire publié (403)', function (): void {
        $provider = marketplaceProvider();
        $offer = createdOffer($provider);

        $provider->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")->assertForbidden();

        $provider->forceFill(['email_verified_at' => now()])->save();
        ProviderProfile::query()->where('user_id', $provider->id)->update(['status' => ProviderProfileStatus::Draft->value]);
        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")->assertForbidden();
    });

    it('limite le nombre d\'offres publiées (409)', function (): void {
        config(['marketplace.offers.max_published' => 1]);
        $provider = marketplaceProvider();
        publishedOfferOf($provider);
        $offer = createdOffer($provider);

        $this->actingAs($provider)->postJson("/api/v1/me/offers/{$offer->uuid}/publish")->assertConflict();
    });
});

describe('lecture publique', function (): void {
    it('ne montre que les offres publiées de prestataires au profil publié, de la plus récente à la plus ancienne', function (): void {
        $provider = marketplaceProvider();
        publishedOfferOf($provider, ['title' => 'Ancienne offre publiée', 'published_at' => now()->subDays(3)]);
        publishedOfferOf($provider, ['title' => 'Récente offre publiée', 'published_at' => now()->subDay()]);
        Offer::factory()->create(['user_id' => $provider->id, 'title' => 'Brouillon jamais publié']);
        publishedOfferOf($provider, ['title' => 'Offre dépubliée', 'status' => OfferStatus::Unpublished]);

        $hidden = marketplaceProvider();
        publishedOfferOf($hidden, ['title' => 'Offre d\'un profil retiré']);
        ProviderProfile::query()->where('user_id', $hidden->id)->update(['status' => ProviderProfileStatus::Draft->value]);

        $this->getJson('/api/v1/offers')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Récente offre publiée', 'Ancienne offre publiée']);
    });

    it('filtre par catégorie (sous-catégories comprises), ville, mode de prix et texte', function (): void {
        $provider = marketplaceProvider();
        $parent = serviceCategory('numerique', 'Numérique');
        $child = serviceCategory('sites', 'Sites', parent: $parent);
        publishedOfferOf($provider, ['title' => 'Site vitrine sur mesure', 'service_category_id' => $child->id, 'city' => 'Lyon']);
        publishedOfferOf($provider, ['title' => 'Comptabilité mensuelle', 'pricing_mode' => 'on_quote', 'price_cents' => null, 'city' => 'Nantes']);

        $this->getJson('/api/v1/offers?category=numerique')->assertJsonPath('data.*.title', ['Site vitrine sur mesure']);
        $this->getJson('/api/v1/offers?city=lyon')->assertJsonPath('data.*.title', ['Site vitrine sur mesure']);
        $this->getJson('/api/v1/offers?pricing_mode=on_quote')->assertJsonPath('data.*.title', ['Comptabilité mensuelle']);
        $this->getJson('/api/v1/offers?q=compta')->assertJsonPath('data.*.title', ['Comptabilité mensuelle']);
        $this->getJson('/api/v1/offers?q=a')->assertUnprocessable();
        $this->getJson('/api/v1/offers?pricing_mode=gratuit')->assertUnprocessable();
    });

    it('présente l\'auteur par son profil public et sa qualité déclarée, sans coordonnées', function (): void {
        $provider = marketplaceProvider();
        $offer = publishedOfferOf($provider);

        $response = $this->getJson("/api/v1/offers/{$offer->slug}")
            ->assertOk()
            ->assertJsonPath('data.pricing', ['mode' => 'from', 'price_cents' => 150000, 'currency' => 'EUR', 'tax_basis' => 'ttc', 'indicative' => true])
            ->assertJsonPath('data.provider.slug', providerSlug($provider))
            ->assertJsonPath('data.seller', [
                'declared_status' => 'professional',
                'legal_name' => 'Camille Exemple',
                'trade_name' => 'Atelier Exemple',
                'registration_number' => '123456782',
                'country' => 'FR',
            ]);

        expectNoPrivateFields($response->json());
        expect($response->json('data'))->not->toHaveKey('uuid')
            ->and($response->getContent())->not->toContain('rue de')
            ->and($response->getContent())->not->toContain('contact@atelier-exemple.test')
            ->and($response->getContent())->not->toContain('23 45 67');
    });

    it('liste les offres d\'un prestataire, et répond 404 pour un profil inconnu ou non publié', function (): void {
        $provider = marketplaceProvider();
        publishedOfferOf($provider);
        publishedOfferOf(marketplaceProvider());

        $this->getJson('/api/v1/providers/'.providerSlug($provider).'/offers')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/providers/inconnu/offers')->assertNotFound();

        ProviderProfile::query()->where('user_id', $provider->id)->update(['status' => ProviderProfileStatus::Draft->value]);
        $this->getJson('/api/v1/providers/'.providerSlug($provider).'/offers')->assertNotFound();
    });
});
