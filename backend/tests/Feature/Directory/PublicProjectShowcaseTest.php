<?php

use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;

/*
| Vitrine publique des projets (décision 71) : GET /public/projects et GET /public/projects/{uuid}.
| Ouverte à tout visiteur, contenu limité, accès anticipé respecté, limite par IP.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
});

it('liste les projets consultables à un visiteur anonyme, du plus récent au plus ancien', function (): void {
    $older = Project::factory()->published(now()->subDays(3))->create(['title' => 'Projet ancien']);
    $newer = Project::factory()->published(now()->subDay())->create(['title' => 'Projet récent']);
    Project::factory()->draft()->create(['title' => 'Brouillon']);
    Project::factory()->expired()->create(['title' => 'Expiré']);
    Project::factory()->closed()->create(['title' => 'Clos']);

    $response = $this->getJson('/api/v1/public/projects')->assertOk();

    expect(array_column($response->json('data'), 'uuid'))->toBe([$newer->uuid, $older->uuid]);
});

it('ne montre qu\'un contenu limité, sans description ni donnée du porteur', function (): void {
    $project = Project::factory()->published()->create(['description' => 'Description confidentielle du projet.']);

    $data = $this->getJson("/api/v1/public/projects/{$project->uuid}")->assertOk()->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([
        'uuid', 'title', 'category', 'budget_min', 'budget_max', 'currency', 'country', 'remote_ok', 'published_at',
    ]);
    expectNoPrivateFields($data);
    expect(json_encode($data))->not->toContain('Description confidentielle');
});

it('respecte la fenêtre d\'accès anticipé des prestataires Premium', function (): void {
    config(['directory.early_access_hours' => 24]);
    $fresh = Project::factory()->published(now()->subHours(2))->create();
    $open = Project::factory()->published(now()->subHours(30))->create();

    $uuids = array_column($this->getJson('/api/v1/public/projects')->assertOk()->json('data'), 'uuid');

    expect($uuids)->toBe([$open->uuid]);
    $this->getJson("/api/v1/public/projects/{$fresh->uuid}")->assertNotFound();
});

it('cache un projet masqué par la modération', function (): void {
    $project = Project::factory()->published()->create();
    restrictContent('project', $project->uuid);

    $this->getJson('/api/v1/public/projects')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/public/projects/{$project->uuid}")->assertNotFound();
});

it('renvoie 404 pour un brouillon ou un projet inconnu', function (): void {
    $draft = Project::factory()->draft()->create();

    $this->getJson("/api/v1/public/projects/{$draft->uuid}")->assertNotFound();
    $this->getJson('/api/v1/public/projects/'.fake()->uuid())->assertNotFound();
});

it('filtre par catégorie, sous-catégories comprises, et par pays', function (): void {
    $parent = serviceCategory('juridique');
    $child = serviceCategory('statuts', parent: $parent);
    $match = Project::factory()->published()->create(['service_category_id' => $child->id, 'country' => 'FR']);
    Project::factory()->published()->create(['service_category_id' => serviceCategory('design')->id, 'country' => 'FR']);
    Project::factory()->published()->create(['service_category_id' => $child->id, 'country' => 'BE']);

    $uuids = array_column($this->getJson('/api/v1/public/projects?category=juridique&country=FR')->assertOk()->json('data'), 'uuid');

    expect($uuids)->toBe([$match->uuid]);
});

it('refuse des filtres invalides (422)', function (): void {
    $this->getJson('/api/v1/public/projects?category=inconnue')->assertUnprocessable()->assertJsonValidationErrors('category');
    $this->getJson('/api/v1/public/projects?country=XX1')->assertUnprocessable()->assertJsonValidationErrors('country');
    $this->getJson('/api/v1/public/projects?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('limite les consultations par IP (429)', function (): void {
    config(['security.rate_limits.public_projects' => ['per_minute' => 2, 'per_day' => 1000]]);

    $this->getJson('/api/v1/public/projects')->assertOk();
    $this->getJson('/api/v1/public/projects')->assertOk();
    $this->getJson('/api/v1/public/projects')->assertTooManyRequests();
});
