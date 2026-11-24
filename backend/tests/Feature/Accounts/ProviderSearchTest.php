<?php

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/**
 * Profil publié (par défaut), avec ses catégories.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<ServiceCategory>  $categories
 */
function searchableProfile(array $attributes = [], array $categories = [], bool $published = true): ProviderProfile
{
    $factory = ProviderProfile::factory();
    $profile = ($published ? $factory->published() : $factory)->create($attributes);
    $profile->categories()->sync(array_map(fn (ServiceCategory $category): int => $category->id, $categories));

    return $profile;
}

/**
 * @return list<string>
 */
function resultSlugs(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'slug');
}

describe('GET /providers', function (): void {
    it('liste uniquement les profils publiés, du plus récent au plus ancien, sans authentification', function (): void {
        searchableProfile(['slug' => 'ancien', 'published_at' => Carbon::parse('2026-11-01')]);
        searchableProfile(['slug' => 'recent', 'published_at' => Carbon::parse('2026-11-09')]);
        searchableProfile(['slug' => 'milieu', 'published_at' => Carbon::parse('2026-11-05')]);
        searchableProfile(['slug' => 'brouillon'], published: false);

        expect(resultSlugs($this->getJson('/api/v1/providers')))->toBe(['recent', 'milieu', 'ancien']);
    });

    it('filtre par catégorie, sous-catégories incluses', function (): void {
        $legal = serviceCategory('juridique');
        $statutes = serviceCategory('statuts', parent: $legal);
        $contracts = serviceCategory('contrats', parent: $legal);
        $design = serviceCategory('design');

        searchableProfile(['slug' => 'parent'], [$legal]);
        searchableProfile(['slug' => 'enfant-statuts'], [$statutes]);
        searchableProfile(['slug' => 'enfant-contrats'], [$contracts, $design]);
        searchableProfile(['slug' => 'designer'], [$design]);
        searchableProfile(['slug' => 'brouillon-juridique'], [$legal], published: false);

        expect(resultSlugs($this->getJson('/api/v1/providers?category=juridique')))
            ->toEqualCanonicalizing(['parent', 'enfant-statuts', 'enfant-contrats'])
            ->and(resultSlugs($this->getJson('/api/v1/providers?category=statuts')))->toBe(['enfant-statuts'])
            ->and(resultSlugs($this->getJson('/api/v1/providers?category=design')))
            ->toEqualCanonicalizing(['enfant-contrats', 'designer']);
    });

    it('n\'inclut pas une sous-catégorie désactivée dans le filtre de son parent', function (): void {
        $legal = serviceCategory('juridique');
        $inactive = serviceCategory('ancien', parent: $legal, active: false);
        searchableProfile(['slug' => 'ancien-profil'], [$inactive]);

        expect(resultSlugs($this->getJson('/api/v1/providers?category=juridique')))->toBe([]);
    });

    it('filtre par pays, en acceptant les minuscules', function (): void {
        searchableProfile(['slug' => 'france', 'country' => 'FR']);
        searchableProfile(['slug' => 'belgique', 'country' => 'BE']);

        expect(resultSlugs($this->getJson('/api/v1/providers?country=be')))->toBe(['belgique']);
    });

    it('filtre par ville, sans tenir compte de la casse', function (): void {
        searchableProfile(['slug' => 'lyon', 'city' => 'Lyon']);
        searchableProfile(['slug' => 'lyon-3', 'city' => 'Lyon 3e']);
        searchableProfile(['slug' => 'paris', 'city' => 'Paris']);

        expect(resultSlugs($this->getJson('/api/v1/providers?city=lyon')))->toBe(['lyon']);
    });

    it('recherche le texte dans le nom, l\'accroche et la bio', function (): void {
        searchableProfile(['slug' => 'nom', 'display_name' => 'Cabinet Comptable Martin', 'headline' => 'x', 'bio' => 'x']);
        searchableProfile(['slug' => 'accroche', 'display_name' => 'Durand', 'headline' => 'Expert COMPTABLE à Lyon', 'bio' => 'x']);
        searchableProfile(['slug' => 'bio', 'display_name' => 'Petit', 'headline' => null, 'bio' => 'Tenue comptable et bilans']);
        searchableProfile(['slug' => 'autre', 'display_name' => 'Studio Graphique', 'headline' => 'Design', 'bio' => 'Logos']);

        expect(resultSlugs($this->getJson('/api/v1/providers?q=comptable')))
            ->toEqualCanonicalizing(['nom', 'accroche', 'bio']);
    });

    it('traite % et _ comme du texte, pas comme des jokers', function (): void {
        searchableProfile(['slug' => 'pourcent', 'display_name' => 'Remise 100% garantie', 'headline' => 'x', 'bio' => 'x']);
        searchableProfile(['slug' => 'souligne', 'display_name' => 'Agence a_b', 'headline' => 'x', 'bio' => 'x']);
        searchableProfile(['slug' => 'exclamation', 'display_name' => 'Wow! Studio', 'headline' => 'x', 'bio' => 'x']);
        searchableProfile(['slug' => 'temoin', 'display_name' => 'Agence axb 1000 garantie', 'headline' => 'x', 'bio' => 'x']);

        expect(resultSlugs($this->getJson('/api/v1/providers?q='.urlencode('0%'))))->toBe(['pourcent'])
            ->and(resultSlugs($this->getJson('/api/v1/providers?q=a_b')))->toBe(['souligne'])
            ->and(resultSlugs($this->getJson('/api/v1/providers?q='.urlencode('%%'))))->toBe([])
            ->and(resultSlugs($this->getJson('/api/v1/providers?q=__')))->toBe([])
            ->and(resultSlugs($this->getJson('/api/v1/providers?q='.urlencode('Wow!'))))->toBe(['exclamation']);
    });

    it('combine les filtres', function (): void {
        $design = serviceCategory('design');
        searchableProfile(['slug' => 'ok', 'country' => 'FR', 'city' => 'Lyon', 'display_name' => 'Studio Lumière'], [$design]);
        searchableProfile(['slug' => 'mauvaise-ville', 'country' => 'FR', 'city' => 'Paris', 'display_name' => 'Studio Paris'], [$design]);
        searchableProfile(['slug' => 'sans-categorie', 'country' => 'FR', 'city' => 'Lyon', 'display_name' => 'Studio Rhône']);

        expect(resultSlugs($this->getJson('/api/v1/providers?category=design&country=FR&city=Lyon&q=studio')))->toBe(['ok']);
    });

    it('ignore les paramètres vides', function (): void {
        searchableProfile(['slug' => 'a']);

        expect(resultSlugs($this->getJson('/api/v1/providers?q=&category=&country=&city=')))->toBe(['a']);
    });

    it('pagine par 20 par défaut et accepte jusqu\'à 50 résultats par page', function (): void {
        ProviderProfile::factory()->published()->count(55)->create();

        $this->getJson('/api/v1/providers')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 55)
            ->assertJsonPath('meta.last_page', 3);

        $this->getJson('/api/v1/providers?page=3')->assertJsonCount(15, 'data');
        $this->getJson('/api/v1/providers?per_page=50')->assertJsonCount(50, 'data');
    });

    it('conserve les filtres dans les liens de pagination', function (): void {
        ProviderProfile::factory()->published()->count(3)->create(['country' => 'FR']);

        $next = $this->getJson('/api/v1/providers?country=FR&per_page=1')->json('links.next');

        expect($next)->toContain('country=FR')->toContain('per_page=1')->toContain('page=2');
    });

    it('refuse les paramètres invalides', function (string $query, string $errorKey): void {
        $this->getJson('/api/v1/providers?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);
    })->with([
        'per_page au-delà du maximum' => ['per_page=51', 'per_page'],
        'per_page nul' => ['per_page=0', 'per_page'],
        'page invalide' => ['page=0', 'page'],
        'catégorie inconnue' => ['category=inconnue', 'category'],
        'pays inconnu' => ['country=XX', 'country'],
        'recherche trop courte' => ['q=a', 'q'],
        'recherche trop longue' => ['q='.str_repeat('a', 101), 'q'],
        'recherche en tableau' => ['q[]=a', 'q'],
    ]);

    it('n\'expose aucune donnée personnelle ni identifiant interne', function (): void {
        $user = User::factory()->create(['email' => 'secret@example.com', 'name' => 'Jean Secret']);
        $profile = searchableProfile(['user_id' => $user->id, 'slug' => 'public'], [serviceCategory('design')]);

        $response = $this->getJson('/api/v1/providers')->assertOk();

        expect(array_keys($response->json('data.0')))->toEqualCanonicalizing([
            'display_name', 'slug', 'headline', 'bio', 'city', 'country', 'website', 'logo_url', 'banner_url', 'categories', 'published_at',
        ]);
        expect($response->getContent())
            ->not->toContain('secret@example.com')
            ->not->toContain('Jean Secret')
            ->not->toContain('"id"')
            ->not->toContain('user_id')
            ->not->toContain('"status"');
        expect($response->json('data.0'))->not->toContain($profile->id);
    });
});
