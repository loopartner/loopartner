<?php

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
});

/**
 * Projet publié dans la catégorie donnée (créée au besoin).
 *
 * @param  array<string, mixed>  $attributes
 */
function liveProject(array $attributes = [], ?ServiceCategory $category = null): Project
{
    $factory = Project::factory()->published($attributes['published_at'] ?? null);
    unset($attributes['published_at']);

    if ($category !== null) {
        $attributes['service_category_id'] = $category->id;
    }

    return $factory->create($attributes);
}

/**
 * @return list<string>
 */
function projectTitles(TestResponse $response): array
{
    return array_column($response->assertOk()->json('data'), 'title');
}

describe('accès', function (): void {
    it('renvoie 401 sans authentification', function (): void {
        $project = liveProject();

        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->getJson("/api/v1/projects/{$project->uuid}")->assertUnauthorized();
    });

    it('refuse les rôles autres que prestataire (403)', function (string $role): void {
        $project = liveProject();
        $user = projectOwner($role);

        $this->actingAs($user)->getJson('/api/v1/projects')->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/projects/{$project->uuid}")->assertForbidden();
    })->with(['particulier', 'entrepreneur', 'redacteur', 'admin']);

    it('refuse un prestataire dont l\'email n\'est pas vérifié (403)', function (): void {
        $project = liveProject();
        $user = projectOwner('prestataire', verified: false);

        $this->actingAs($user)->getJson('/api/v1/projects')->assertForbidden();
        $this->actingAs($user)->getJson("/api/v1/projects/{$project->uuid}")->assertForbidden();
    });
});

describe('GET /projects', function (): void {
    it('liste uniquement les projets publiés et non expirés, du plus récent au plus ancien', function (): void {
        liveProject(['title' => 'Ancien', 'published_at' => now()->subDays(5)]);
        liveProject(['title' => 'Récent', 'published_at' => now()->subDay()]);
        liveProject(['title' => 'Expire bientôt', 'published_at' => now()->subDays(59)]);
        Project::factory()->draft()->create(['title' => 'Brouillon']);
        Project::factory()->closed()->create(['title' => 'Clos']);
        Project::factory()->expired()->create(['title' => 'Expiré non clôturé']);

        expect(projectTitles($this->actingAs(browsingProvider())->getJson('/api/v1/projects')))
            ->toBe(['Récent', 'Ancien', 'Expire bientôt']);
    });

    it('masque un projet dès son expiration, avant la clôture quotidienne', function (): void {
        liveProject(['title' => 'Bientôt expiré']);
        $provider = browsingProvider();

        expect(projectTitles($this->actingAs($provider)->getJson('/api/v1/projects')))->toBe(['Bientôt expiré']);

        Carbon::setTestNow(now()->addDays(Project::PUBLICATION_DAYS));

        expect(projectTitles($this->actingAs($provider)->getJson('/api/v1/projects')))->toBe([]);
    });

    it('filtre par catégorie, sous-catégories incluses', function (): void {
        $web = serviceCategory('developpement-web');
        $showcase = serviceCategory('site-vitrine', parent: $web);
        $design = serviceCategory('design');
        liveProject(['title' => 'Parent'], $web);
        liveProject(['title' => 'Enfant'], $showcase);
        liveProject(['title' => 'Autre'], $design);
        $provider = browsingProvider();

        expect(projectTitles($this->actingAs($provider)->getJson('/api/v1/projects?category=developpement-web')))
            ->toEqualCanonicalizing(['Parent', 'Enfant'])
            ->and(projectTitles($this->actingAs($provider)->getJson('/api/v1/projects?category=site-vitrine')))->toBe(['Enfant']);
    });

    it('filtre par pays', function (): void {
        liveProject(['title' => 'France', 'country' => 'FR']);
        liveProject(['title' => 'Suisse', 'country' => 'CH']);

        expect(projectTitles($this->actingAs(browsingProvider())->getJson('/api/v1/projects?country=ch')))->toBe(['Suisse']);
    });

    it('filtre sur le télétravail', function (string $value, array $expected): void {
        liveProject(['title' => 'À distance', 'remote_ok' => true]);
        liveProject(['title' => 'Sur place', 'remote_ok' => false]);

        expect(projectTitles($this->actingAs(browsingProvider())->getJson('/api/v1/projects?remote_ok='.$value)))
            ->toBe($expected);
    })->with([
        'true' => ['true', ['À distance']],
        '1' => ['1', ['À distance']],
        'false' => ['false', ['Sur place']],
        '0' => ['0', ['Sur place']],
    ]);

    it('filtre par budget, en EUR par défaut', function (): void {
        liveProject(['title' => '500-1000', 'budget_min' => 500, 'budget_max' => 1000]);
        liveProject(['title' => '2000-5000', 'budget_min' => 2000, 'budget_max' => 5000]);
        liveProject(['title' => 'Min 3000', 'budget_min' => 3000, 'budget_max' => null]);
        liveProject(['title' => 'Max 800', 'budget_min' => null, 'budget_max' => 800]);
        liveProject(['title' => 'Sans budget']);
        liveProject(['title' => 'CHF 2000-5000', 'budget_min' => 2000, 'budget_max' => 5000, 'currency' => 'CHF']);
        $provider = browsingProvider();

        $titles = fn (string $query): array => projectTitles($this->actingAs($provider)->getJson('/api/v1/projects?'.$query));

        expect($titles('budget_min=1500'))->toEqualCanonicalizing(['2000-5000', 'Min 3000'])
            ->and($titles('budget_max=900'))->toEqualCanonicalizing(['500-1000', 'Max 800'])
            ->and($titles('budget_min=900&budget_max=2500'))->toEqualCanonicalizing(['500-1000', '2000-5000'])
            ->and($titles('budget_min=1500&currency=chf'))->toBe(['CHF 2000-5000'])
            ->and($titles('currency=CHF'))->toBe(['CHF 2000-5000']);
    });

    it('combine les filtres', function (): void {
        $design = serviceCategory('design');
        liveProject(['title' => 'OK', 'country' => 'FR', 'remote_ok' => true, 'budget_min' => 1000, 'budget_max' => 2000], $design);
        liveProject(['title' => 'Pas à distance', 'country' => 'FR', 'remote_ok' => false, 'budget_min' => 1000, 'budget_max' => 2000], $design);
        liveProject(['title' => 'Autre pays', 'country' => 'BE', 'remote_ok' => true, 'budget_min' => 1000, 'budget_max' => 2000], $design);

        expect(projectTitles($this->actingAs(browsingProvider())
            ->getJson('/api/v1/projects?category=design&country=FR&remote_ok=true&budget_min=1500')))->toBe(['OK']);
    });

    it('pagine par 20 et refuse plus de 50 résultats par page', function (): void {
        Project::factory()->published()->count(21)->create();
        $provider = browsingProvider();

        $this->actingAs($provider)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.total', 21);
        $this->actingAs($provider)->getJson('/api/v1/projects?page=2')->assertJsonCount(1, 'data');
        $this->actingAs($provider)->getJson('/api/v1/projects?per_page=50')->assertJsonCount(21, 'data');
    });

    it('refuse les filtres invalides', function (string $query, string $errorKey): void {
        $this->actingAs(browsingProvider())
            ->getJson('/api/v1/projects?'.$query)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$errorKey]);
    })->with([
        'per_page au-delà du maximum' => ['per_page=51', 'per_page'],
        'catégorie inconnue' => ['category=inconnue', 'category'],
        'pays inconnu' => ['country=XX', 'country'],
        'télétravail invalide' => ['remote_ok=peut-etre', 'remote_ok'],
        'budget négatif' => ['budget_min=-1', 'budget_min'],
        'budget non entier' => ['budget_max=abc', 'budget_max'],
        'devise inconnue' => ['currency=ABC', 'currency'],
    ]);

    it('n\'expose aucune donnée personnelle du porteur de projet', function (): void {
        $owner = User::factory()->create(['email' => 'porteur@example.com', 'name' => 'Camille Porteur', 'country' => 'BE']);
        $owner->assignRole('entrepreneur');
        $project = Project::factory()->for($owner)->published()->create(['country' => 'FR']);

        $response = $this->actingAs(browsingProvider())->getJson('/api/v1/projects')->assertOk();

        expect(array_keys($response->json('data.0')))->toEqualCanonicalizing([
            'uuid', 'title', 'description', 'category', 'budget_min', 'budget_max', 'currency', 'country',
            'city', 'remote_ok', 'desired_start_date', 'published_at', 'expires_at', 'owner',
        ]);
        expect($response->json('data.0.owner'))->toBe(['account_type' => 'entrepreneur', 'country' => 'BE']);
        expect($response->getContent())
            ->not->toContain('porteur@example.com')
            ->not->toContain('Camille Porteur')
            ->not->toContain('"id"')
            ->not->toContain('user_id')
            ->not->toContain('"status"')
            ->not->toContain('closed_at');
        expect($response->json('data.0'))->not->toContain($owner->id)->not->toContain($project->id);
    });
});

describe('GET /projects/{uuid}', function (): void {
    it('affiche un projet publié avec sa catégorie traduite et le type de compte du porteur', function (): void {
        $owner = projectOwner('particulier');
        $owner->forceFill(['country' => 'FR'])->save();
        $project = Project::factory()->for($owner)->published()->create([
            'title' => 'Logo pour mon traiteur',
            'service_category_id' => serviceCategory('identite-visuelle', 'Identité visuelle', 'Brand identity')->id,
        ]);

        $this->actingAs(browsingProvider())
            ->getJson("/api/v1/projects/{$project->uuid}?locale=en")
            ->assertOk()
            ->assertJsonPath('data.uuid', $project->uuid)
            ->assertJsonPath('data.title', 'Logo pour mon traiteur')
            ->assertJsonPath('data.category', ['slug' => 'identite-visuelle', 'name' => 'Brand identity'])
            ->assertJsonPath('data.owner', ['account_type' => 'particulier', 'country' => 'FR'])
            ->assertJsonMissingPath('data.owner.email')
            ->assertJsonMissingPath('data.owner.name');
    });

    it('renvoie 404 pour un brouillon, un projet clos, expiré ou inconnu', function (string $state): void {
        $uuid = $state === 'unknown'
            ? '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'
            : Project::factory()->{$state}()->create()->uuid;

        $this->actingAs(browsingProvider())
            ->getJson("/api/v1/projects/{$uuid}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Projet introuvable.');
    })->with(['draft', 'closed', 'expired', 'unknown']);
});
