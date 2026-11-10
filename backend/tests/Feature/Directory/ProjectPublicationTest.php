<?php

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
});

describe('POST /me/projects/{uuid}/publish', function (): void {
    it('publie un brouillon pour 60 jours', function (): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$project->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', '2026-11-10T09:00:00Z')
            ->assertJsonPath('data.expires_at', '2027-01-09T09:00:00Z')
            ->assertJsonPath('data.closed_at', null);

        expect($project->fresh()->isLive())->toBeTrue();
    });

    it('exige un email vérifié (403)', function (): void {
        $user = projectOwner('entrepreneur', verified: false);
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$project->uuid}/publish")
            ->assertForbidden()
            ->assertJsonPath('message', 'Vous devez vérifier votre adresse email avant de publier un projet.');

        expect($project->fresh()->status)->toBe(ProjectStatus::Draft);
    });

    it('refuse de publier un projet déjà publié (409)', function (): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->published(now()->subDay())->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$project->uuid}/publish")
            ->assertConflict()
            ->assertJsonPath('message', 'Ce projet est déjà publié.');

        expect($project->fresh()->published_at->toIso8601ZuluString())->toBe('2026-11-09T09:00:00Z');
    });

    it('republie un projet clos ou expiré pour 60 jours', function (string $state): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->{$state}()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$project->uuid}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_expired', false)
            ->assertJsonPath('data.published_at', '2026-11-10T09:00:00Z')
            ->assertJsonPath('data.expires_at', '2027-01-09T09:00:00Z')
            ->assertJsonPath('data.closed_at', null);
    })->with(['closed', 'expired']);

    it('limite à 10 projets publiés en même temps (409 au-delà)', function (): void {
        $user = projectOwner('entrepreneur');
        Project::factory()->for($user)->published()->count(Project::MAX_PUBLISHED_PER_USER)->create();
        $draft = Project::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$draft->uuid}/publish")
            ->assertConflict()
            ->assertJsonPath('message', 'Vous avez déjà 10 projets publiés : clôturez-en un avant d\'en publier un autre.');

        expect($draft->fresh()->status)->toBe(ProjectStatus::Draft);

        // Après une clôture, une place se libère.
        $live = Project::query()->where('user_id', $user->id)->live()->firstOrFail();
        $this->actingAs($user)->postJson("/api/v1/me/projects/{$live->uuid}/close")->assertOk();

        $this->actingAs($user)->postJson("/api/v1/me/projects/{$draft->uuid}/publish")->assertOk();
    });

    it('ne compte dans le plafond ni les projets clos ou expirés, ni ceux des autres', function (): void {
        $user = projectOwner('particulier');
        Project::factory()->for($user)->published()->count(Project::MAX_PUBLISHED_PER_USER - 1)->create();
        Project::factory()->for($user)->closed()->count(2)->create();
        Project::factory()->for($user)->expired()->count(2)->create();
        Project::factory()->published()->count(3)->create();
        $draft = Project::factory()->for($user)->create();

        $this->actingAs($user)->postJson("/api/v1/me/projects/{$draft->uuid}/publish")->assertOk();
    });
});

describe('POST /me/projects/{uuid}/close', function (): void {
    it('clôture un projet publié, même expiré', function (string $state): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->{$state}()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$project->uuid}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_at', '2026-11-10T09:00:00Z');
    })->with(['published', 'expired']);

    it('refuse de clôturer un brouillon ou un projet déjà clos (409)', function (string $state): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->{$state}()->create();

        $this->actingAs($user)
            ->postJson("/api/v1/me/projects/{$project->uuid}/close")
            ->assertConflict()
            ->assertJsonPath('message', 'Seul un projet publié peut être clôturé.');
    })->with(['draft', 'closed']);

    it('permet la clôture sans email vérifié', function (): void {
        $user = projectOwner('particulier', verified: false);
        $project = Project::factory()->for($user)->published()->create();

        $this->actingAs($user)->postJson("/api/v1/me/projects/{$project->uuid}/close")->assertOk();
    });
});
