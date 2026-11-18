<?php

use App\Models\ServiceCategory;
use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\Templates;
use Database\Seeders\ServiceCategorySeeder;
use Illuminate\Support\Facades\DB;

/*
| Module Workspaces (décisions 3 et 63) : espaces privés des particuliers et des entrepreneurs ;
| quotas gratuit et Premium ; fin du Premium ; contenu chiffré ; objectifs, étapes, tâches,
| cahiers des charges ; modèles de projet (Premium).
*/

beforeEach(function (): void {
    serviceCategory('site-vitrine');
});

it('crée un workspace privé, sans email vérifié, avec son contenu chiffré en base', function (string $role): void {
    $user = workspaceOwner($role, verified: false);

    $response = $this->actingAs($user)->postJson('/api/v1/me/workspaces', [
        'title' => 'Ouvrir mon atelier de poterie',
        'description' => 'Je veux ouvrir un atelier et proposer des cours le week-end.',
        'country' => 'fr',
        'budget_min' => 3000,
        'budget_max' => 8000,
        'remote_ok' => false,
        'desired_start_date' => '2027-03-01',
    ])->assertCreated();

    $response->assertJsonPath('data.title', 'Ouvrir mon atelier de poterie')
        ->assertJsonPath('data.country', 'FR')
        ->assertJsonPath('data.budget', ['min' => 3000, 'max' => 8000, 'currency' => 'EUR'])
        ->assertJsonPath('data.status', 'active')
        ->assertJsonPath('data.locked', false)
        ->assertJsonPath('data.progress.percent', 0)
        ->assertJsonMissingPath('data.id')
        ->assertJsonMissingPath('data.user_id');

    $raw = (array) DB::table('workspaces')->sole();
    expect(json_encode($raw))->not->toContain('atelier de poterie')
        ->and(json_encode($raw))->not->toContain('cours le week-end')
        ->and(securityLog('workspaces.created'))->toHaveCount(1)
        ->and(json_encode(securityLog()))->not->toContain('poterie');
})->with(['particulier', 'entrepreneur']);

it('refuse les workspaces aux autres rôles (403) et aux anonymes (401)', function (string $role): void {
    $user = projectOwner($role);

    $this->actingAs($user)->getJson('/api/v1/me/workspaces')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Mon projet'])->assertForbidden();
})->with(['prestataire', 'redacteur', 'admin']);

it('refuse un anonyme', function (): void {
    $this->getJson('/api/v1/me/workspaces')->assertUnauthorized();
    $this->postJson('/api/v1/me/workspaces', ['title' => 'Mon projet'])->assertUnauthorized();
});

it('répond 404 sur le workspace d\'un autre utilisateur, même avec une requête invalide', function (): void {
    $workspace = workspaceOf(workspaceOwner());
    $other = workspaceOwner();
    $base = '/api/v1/me/workspaces/'.$workspace->uuid;
    $step = Step::factory()->for($workspace)->create();

    $this->actingAs($other)->getJson($base)->assertNotFound();
    $this->actingAs($other)->patchJson($base, ['title' => 'Repris'])->assertNotFound();
    $this->actingAs($other)->patchJson($base, ['title' => ''])->assertNotFound();
    $this->actingAs($other)->postJson($base.'/archive')->assertNotFound();
    $this->actingAs($other)->postJson($base.'/goals', ['title' => 'Objectif'])->assertNotFound();
    $this->actingAs($other)->postJson($base.'/steps/'.$step->uuid.'/tasks', ['title' => 'Tâche'])->assertNotFound();
    $this->actingAs($other)->getJson($base.'/specifications')->assertNotFound();
    $this->actingAs($other)->deleteJson($base)->assertNotFound();

    // L'élément d'un workspace donné par l'URL d'un autre workspace n'existe pas non plus.
    $mine = workspaceOf($other);
    $this->actingAs($other)->patchJson('/api/v1/me/workspaces/'.$mine->uuid.'/steps/'.$step->uuid, ['title' => 'Volée'])->assertNotFound();

    expect(Workspace::query()->whereKey($workspace->id)->exists())->toBeTrue()
        ->and($step->fresh()?->title)->toBe('Choisir un prestataire');
});

it('refuse les champs fixés par la plateforme, le HTML et les caractères invisibles (422)', function (array $payload, string $field): void {
    $this->actingAs(workspaceOwner())->postJson('/api/v1/me/workspaces', ['title' => 'Mon projet', ...$payload])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'statut' => [['status' => 'archived'], 'status'],
    'propriétaire' => [['user_id' => 1], 'user_id'],
    'projet relié' => [['linked_project_id' => 1], 'linked_project_id'],
    'modèle interne' => [['template_code' => 'creer-son-site'], 'template_code'],
    'HTML' => [['description' => 'Mon <b>projet</b>'], 'description'],
    'invisible' => [['title' => "Mon\u{200B}projet"], 'title'],
    'pays inconnu' => [['country' => 'XX'], 'country'],
    'budget inversé' => [['budget_min' => 900, 'budget_max' => 100], 'budget_min'],
]);

it('limite le gratuit à un workspace actif (403 premium_required) et libère la place en archivant', function (): void {
    $user = workspaceOwner();
    $first = workspaceOf($user);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Deuxième projet'])
        ->assertForbidden()
        ->assertJsonPath('code', 'premium_required')
        ->assertJsonPath('feature', 'multiple_workspaces');

    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$first->uuid.'/archive')->assertOk()->assertJsonPath('data.status', 'archived');
    $second = $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Deuxième projet'])->assertCreated()->json('data.uuid');

    // Archivé : lecture seule (409), désarchivage refusé tant que le quota est plein.
    $this->actingAs($user)->patchJson('/api/v1/me/workspaces/'.$first->uuid, ['title' => 'Changé'])->assertConflict();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$first->uuid.'/unarchive')->assertForbidden()->assertJsonPath('code', 'premium_required');

    $this->actingAs($user)->getJson('/api/v1/me/workspaces')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.uuid', $second)
        ->assertJsonPath('meta.active', 1)
        ->assertJsonPath('meta.active_limit', 1)
        ->assertJsonPath('meta.templates', false);
});

it('permet dix workspaces actifs en Premium, puis verrouille les plus anciens à la fin du Premium', function (): void {
    $user = workspaceOwner('entrepreneur');
    $grant = makePremium($user);

    foreach (range(1, 10) as $index) {
        $this->travel(1)->minutes();
        $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Projet numéro '.$index])->assertCreated();
    }
    $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Projet de trop'])->assertConflict();

    $grant->forceFill(['revoked_at' => now()])->save();

    $workspaces = Workspace::query()->where('user_id', $user->id)->orderBy('activated_at')->get();
    $oldest = $workspaces->first();
    $newest = $workspaces->last();

    $this->actingAs($user)->getJson('/api/v1/me/workspaces/'.$newest->uuid)->assertOk()->assertJsonPath('data.locked', false);
    $this->actingAs($user)->getJson('/api/v1/me/workspaces/'.$oldest->uuid)->assertOk()->assertJsonPath('data.locked', true);
    $this->actingAs($user)->patchJson('/api/v1/me/workspaces/'.$oldest->uuid, ['title' => 'Modifié'])
        ->assertForbidden()
        ->assertJsonPath('feature', 'multiple_workspaces');
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$oldest->uuid.'/goals', ['title' => 'Objectif'])->assertForbidden();

    // L'utilisateur choisit le workspace qu'il garde modifiable.
    $this->travel(1)->minutes();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$oldest->uuid.'/activate')->assertOk()->assertJsonPath('data.locked', false);
    $this->actingAs($user)->patchJson('/api/v1/me/workspaces/'.$oldest->uuid, ['title' => 'Modifié'])->assertOk();
    $this->actingAs($user)->getJson('/api/v1/me/workspaces/'.$newest->uuid)->assertJsonPath('data.locked', true);

    // Rien n'est supprimé ; un workspace verrouillé reste supprimable.
    expect(Workspace::query()->where('user_id', $user->id)->count())->toBe(10);
    $this->actingAs($user)->deleteJson('/api/v1/me/workspaces/'.$newest->uuid)->assertNoContent();
});

it('gère objectifs, étapes et tâches, avec l\'avancement et la recherche de prestataires', function (): void {
    $user = workspaceOwner();
    $workspace = workspaceOf($user, ['country' => 'BE']);
    $base = '/api/v1/me/workspaces/'.$workspace->uuid;

    $this->actingAs($user)->postJson($base.'/goals', ['title' => 'Ouvrir avant juin', 'target_date' => '2027-06-01'])
        ->assertCreated()->assertJsonPath('data.achieved', false);

    $step = $this->actingAs($user)->postJson($base.'/steps', ['title' => 'Faire réaliser le site', 'category' => 'site-vitrine', 'due_date' => '2027-04-15'])
        ->assertCreated()
        ->assertJsonPath('data.category.slug', 'site-vitrine')
        ->assertJsonPath('data.providers_search', ['category' => 'site-vitrine', 'country' => 'BE'])
        ->assertJsonPath('data.source', 'user')
        ->assertJsonPath('data.ai_generated', false)
        ->json('data.uuid');

    $first = $this->actingAs($user)->postJson($base.'/steps/'.$step.'/tasks', ['title' => 'Écrire le brief', 'notes' => 'Cinq pages.'])->assertCreated()->json('data.uuid');
    $second = $this->actingAs($user)->postJson($base.'/steps/'.$step.'/tasks', ['title' => 'Demander trois devis'])->assertCreated()->json('data.uuid');
    $this->actingAs($user)->patchJson($base.'/tasks/'.$first, ['completed' => true])->assertOk()->assertJsonPath('data.completed', true);

    $this->actingAs($user)->getJson($base)
        ->assertOk()
        ->assertJsonPath('data.progress', ['steps_total' => 1, 'steps_done' => 0, 'tasks_total' => 2, 'tasks_done' => 1, 'percent' => 50])
        ->assertJsonPath('data.steps.0.tasks.0.uuid', $first)
        ->assertJsonPath('data.steps.0.tasks.0.notes', 'Cinq pages.')
        ->assertJsonPath('data.goals.0.title', 'Ouvrir avant juin');

    $this->actingAs($user)->putJson($base.'/steps/'.$step.'/tasks/order', ['order' => [$second, $first]])->assertNoContent();
    $this->actingAs($user)->getJson($base)->assertJsonPath('data.steps.0.tasks.0.uuid', $second);

    // Liste incomplète ou étrangère : 422.
    $this->actingAs($user)->putJson($base.'/steps/'.$step.'/tasks/order', ['order' => [$second]])->assertUnprocessable()->assertJsonValidationErrors('order');

    // Catégorie inconnue : 422 ; déplacement d'une tâche vers une étape d'un autre workspace : 422.
    $this->actingAs($user)->patchJson($base.'/steps/'.$step, ['category' => 'inconnue'])->assertUnprocessable();
    $foreign = Step::factory()->create();
    $this->actingAs($user)->patchJson($base.'/tasks/'.$first, ['step' => $foreign->uuid])->assertUnprocessable()->assertJsonValidationErrors('step');

    // Déplacement vers une autre étape du même workspace.
    $other = $this->actingAs($user)->postJson($base.'/steps', ['title' => 'Préparer l\'ouverture'])->json('data.uuid');
    $this->actingAs($user)->patchJson($base.'/tasks/'.$first, ['step' => $other])->assertOk();
    expect(Task::query()->where('uuid', $first)->sole()->step->uuid)->toBe($other);

    // Supprimer une étape supprime ses tâches.
    $this->actingAs($user)->deleteJson($base.'/steps/'.$step)->assertNoContent();
    expect(Task::query()->where('uuid', $second)->exists())->toBeFalse();
});

it('refuse de modifier la position, l\'origine ou la marque IA d\'un élément (422)', function (string $field, mixed $value): void {
    $user = workspaceOwner();
    $step = Step::factory()->for(workspaceOf($user))->create();

    $this->actingAs($user)->patchJson('/api/v1/me/workspaces/'.$step->workspace->uuid.'/steps/'.$step->uuid, [$field => $value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    ['position', 3],
    ['source', 'copilot'],
    ['ai_generated', true],
    ['completed_at', '2027-01-01'],
]);

it('limite le contenu d\'un workspace (409)', function (): void {
    config(['workspaces.limits.goals' => 1, 'workspaces.limits.steps' => 1, 'workspaces.limits.tasks_per_step' => 1]);
    $user = workspaceOwner();
    $workspace = workspaceOf($user);
    $base = '/api/v1/me/workspaces/'.$workspace->uuid;

    $this->actingAs($user)->postJson($base.'/goals', ['title' => 'Premier objectif'])->assertCreated();
    $this->actingAs($user)->postJson($base.'/goals', ['title' => 'Second objectif'])->assertConflict();

    $step = $this->actingAs($user)->postJson($base.'/steps', ['title' => 'Première étape'])->json('data.uuid');
    $this->actingAs($user)->postJson($base.'/steps', ['title' => 'Seconde étape'])->assertConflict();

    $this->actingAs($user)->postJson($base.'/steps/'.$step.'/tasks', ['title' => 'Tâche 1'])->assertCreated();
    $this->actingAs($user)->postJson($base.'/steps/'.$step.'/tasks', ['title' => 'Tâche 2'])->assertConflict();
});

it('gère les cahiers des charges à sections fixes, chiffrés en base', function (): void {
    $user = workspaceOwner();
    $workspace = workspaceOf($user);
    $step = Step::factory()->for($workspace)->create();
    $base = '/api/v1/me/workspaces/'.$workspace->uuid.'/specifications';

    $uuid = $this->actingAs($user)->postJson($base, [
        'title' => 'Cahier des charges du site',
        'category' => 'site-vitrine',
        'step' => $step->uuid,
        'sections' => ['context' => 'Boutique de céramique artisanale.', 'deliverables' => 'Site de cinq pages.'],
    ])->assertCreated()
        ->assertJsonPath('data.sections.context', 'Boutique de céramique artisanale.')
        ->assertJsonPath('data.sections.budget', null)
        ->assertJsonPath('data.step', $step->uuid)
        ->assertJsonPath('data.ai_generated', false)
        ->json('data.uuid');

    expect(json_encode(DB::table('workspace_specifications')->get()))->not->toContain('céramique');

    $this->actingAs($user)->putJson($base.'/'.$uuid, ['title' => 'Cahier des charges', 'sections' => ['scope' => 'Vitrine seulement.']])
        ->assertOk()
        ->assertJsonPath('data.sections.context', null)
        ->assertJsonPath('data.sections.scope', 'Vitrine seulement.');

    $this->actingAs($user)->postJson($base, ['title' => 'Autre cahier', 'sections' => ['secret' => 'x']])
        ->assertUnprocessable()->assertJsonValidationErrors('sections');

    $this->actingAs($user)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($user)->deleteJson($base.'/'.$uuid)->assertNoContent();
});

it('réserve les modèles de projet au Premium et copie leurs étapes dans la langue de l\'utilisateur', function (): void {
    foreach (['etude-de-marche', 'modele-economique', 'business-plan', 'creation-statuts', 'identite-visuelle', 'strategie-marketing', 'photo-video', 'design-ui-ux', 'referencement-seo'] as $slug) {
        serviceCategory($slug);
    }
    $user = workspaceOwner();

    $this->actingAs($user)->getJson('/api/v1/workspace-templates')
        ->assertOk()
        ->assertJsonPath('data.0.premium', true)
        ->assertJsonPath('data.0.available', false);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Mon site', 'template' => 'creer-son-site'])
        ->assertForbidden()
        ->assertJsonPath('feature', 'workspace_templates');

    makePremium($user);
    $user->forceFill(['locale' => 'en'])->save();
    $uuid = $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Mon site', 'template' => 'creer-son-site'])
        ->assertCreated()
        ->assertJsonPath('data.template', 'creer-son-site')
        ->assertJsonPath('data.steps.0.title', 'Define the need')
        ->assertJsonPath('data.steps.0.source', 'template')
        ->assertJsonPath('data.steps.2.category.slug', 'site-vitrine')
        ->json('data.uuid');

    expect(Step::query()->whereHas('workspace', fn ($query) => $query->where('uuid', $uuid))->count())->toBe(5)
        ->and(Task::query()->where('source', ItemSource::Template->value)->count())->toBeGreaterThan(5);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Autre', 'template' => 'inconnu'])
        ->assertUnprocessable()->assertJsonValidationErrors('template');
});

it('livre des modèles valides : catégories existantes, textes en français et en anglais', function (): void {
    $this->seed(ServiceCategorySeeder::class);
    $templates = app(Templates::class)->all();

    expect($templates)->toHaveCount(4);

    foreach ($templates as $template) {
        foreach (['fr', 'en'] as $locale) {
            expect($template['title'][$locale])->toBeString()->not->toBe('')
                ->and($template['description'][$locale])->toBeString();
        }

        foreach ($template['steps'] as $step) {
            expect($step['title'])->toHaveKeys(['fr', 'en']);

            if ($step['category'] !== null) {
                expect(ServiceCategory::query()->active()->where('slug', $step['category'])->exists())->toBeTrue();
            }

            foreach ($step['tasks'] as $task) {
                expect($task)->toHaveKeys(['fr', 'en']);
            }
        }
    }
});

it('supprime tout le contenu avec le workspace', function (): void {
    $user = workspaceOwner();
    $workspace = workspaceOf($user);
    $step = Step::factory()->for($workspace)->create();
    Task::factory()->for($step)->create();
    Goal::factory()->for($workspace)->create();

    $this->actingAs($user)->deleteJson('/api/v1/me/workspaces/'.$workspace->uuid)->assertNoContent();

    expect(Step::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0)
        ->and(Goal::query()->count())->toBe(0)
        ->and(securityLog('workspaces.deleted'))->toHaveCount(1);
});

it('limite le débit des écritures (429)', function (): void {
    config(['security.rate_limits.workspace_writes.per_hour' => 2]);
    $user = workspaceOwner();
    $workspace = workspaceOf($user);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/goals', ['title' => 'Objectif 1'])->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/goals', ['title' => 'Objectif 2'])->assertCreated();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/goals', ['title' => 'Objectif 3'])->assertTooManyRequests();
});
