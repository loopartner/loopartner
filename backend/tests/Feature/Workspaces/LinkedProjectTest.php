<?php

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use App\Modules\Workspaces\Models\Workspace;

/*
| Lien entre un workspace et un projet Directory (décision 69) : seulement un projet de
| l'utilisateur ; brouillon créé à partir du workspace, jamais publié.
*/

beforeEach(function (): void {
    serviceCategory('site-vitrine');
});

it('relie un projet de l\'utilisateur, puis le détache', function (): void {
    $user = workspaceOwner();
    $workspace = workspaceOf($user);
    $project = publishedProjectOf($user);
    $base = '/api/v1/me/workspaces/'.$workspace->uuid;

    $this->actingAs($user)->postJson($base.'/linked-project', ['project' => $project->uuid])
        ->assertOk()
        ->assertJsonPath('data.linked_project', ['uuid' => $project->uuid, 'title' => $project->title, 'status' => 'published']);

    $this->actingAs($user)->deleteJson($base.'/linked-project')->assertOk()->assertJsonPath('data.linked_project', null);
});

it('refuse de relier le projet d\'un autre utilisateur (422) ou un projet déjà relié (409)', function (): void {
    $user = workspaceOwner();
    $workspace = workspaceOf($user);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/linked-project', ['project' => publishedProjectOf()->uuid])
        ->assertUnprocessable()->assertJsonValidationErrors('project');

    makePremium($user);
    $project = publishedProjectOf($user);
    $other = workspaceOf($user);
    $other->forceFill(['linked_project_id' => $project->id])->save();

    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/linked-project', ['project' => $project->uuid])->assertConflict();
});

it('crée un brouillon Directory à partir du workspace, sans jamais le publier', function (): void {
    $user = workspaceOwner('entrepreneur');
    $workspace = workspaceOf($user, ['title' => 'Site de ma boutique', 'description' => 'Un site vitrine de cinq pages pour présenter mes créations.']);

    $uuid = $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/directory-draft', ['category' => 'site-vitrine'])
        ->assertCreated()
        ->assertJsonPath('data.project.status', 'draft')
        ->json('data.project.uuid');

    $project = Project::query()->where('uuid', $uuid)->sole();
    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->user_id)->toBe($user->id)
        ->and($project->title)->toBe('Site de ma boutique')
        ->and($project->budget_max)->toBe(4000)
        ->and($workspace->fresh()?->linked_project_id)->toBe($project->id)
        ->and(securityLog('workspaces.directory_draft_created'))->toHaveCount(1);

    // Déjà relié : 409 ; demande de publication directe : refusée (422).
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/directory-draft', ['category' => 'site-vitrine'])->assertConflict();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.workspaceOf(workspaceOwner())->uuid.'/directory-draft', ['category' => 'site-vitrine', 'publish' => true])
        ->assertNotFound();
});

it('applique les règles d\'un projet Directory au brouillon (422)', function (): void {
    $user = workspaceOwner();
    $workspace = workspaceOf($user, ['description' => null]);

    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/directory-draft', ['category' => 'site-vitrine'])
        ->assertUnprocessable()->assertJsonValidationErrors('description');
    $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/directory-draft', ['category' => 'site-vitrine', 'publish' => true])
        ->assertUnprocessable()->assertJsonValidationErrors('publish');

    expect(Project::query()->count())->toBe(0);
});

it('détache le projet supprimé du workspace', function (): void {
    $user = workspaceOwner();
    $project = publishedProjectOf($user);
    $workspace = workspaceOf($user);
    $workspace->forceFill(['linked_project_id' => $project->id])->save();

    $this->actingAs($user)->deleteJson('/api/v1/me/projects/'.$project->uuid)->assertNoContent();

    expect($workspace->fresh()?->linked_project_id)->toBeNull();
});

describe('requêtes simultanées', function (): void {
    it('répond 409 quand une autre requête relie le même projet juste avant (index unique)', function (): void {
        $user = workspaceOwner();
        makePremium($user);
        $workspace = workspaceOf($user);
        $other = workspaceOf($user);
        $project = publishedProjectOf($user);

        simulateConcurrentRequest(Workspace::class, 'updating', function () use ($other, $project): void {
            $other->forceFill(['linked_project_id' => $project->id])->save();
        });

        $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/linked-project', ['project' => $project->uuid])
            ->assertConflict();

        expect($workspace->fresh()?->linked_project_id)->toBeNull();
    });

    it('ne crée pas de second brouillon quand une autre requête vient de relier le workspace', function (): void {
        $user = workspaceOwner();
        $workspace = workspaceOf($user, ['description' => 'Un site vitrine de cinq pages pour présenter mes créations.']);
        $project = publishedProjectOf($user);

        // L'autre requête relie le workspace après son chargement par celle du test.
        simulateConcurrentRequest(Workspace::class, 'retrieved', function () use ($workspace, $project): void {
            Workspace::query()->whereKey($workspace->id)->update(['linked_project_id' => $project->id]);
        });

        $this->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/directory-draft', ['category' => 'site-vitrine'])
            ->assertConflict();

        expect(Project::query()->count())->toBe(1)
            ->and($workspace->fresh()?->linked_project_id)->toBe($project->id)
            ->and(securityLog('workspaces.directory_draft_created'))->toHaveCount(0);
    });
});
