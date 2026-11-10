<?php

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;

it('clôture uniquement les projets publiés dont l\'expiration est passée', function (): void {
    Carbon::setTestNow('2026-11-10 03:00:00');

    $expired = Project::factory()->expired()->create();
    $expiringNow = Project::factory()->published(now()->subDays(Project::PUBLICATION_DAYS))->create();
    $live = Project::factory()->published(now()->subDays(Project::PUBLICATION_DAYS - 1))->create();
    $draft = Project::factory()->draft()->create();
    $closed = Project::factory()->closed()->create(['closed_at' => now()->subDays(3)]);

    $this->artisan('projects:close-expired')
        ->expectsOutput('2 projet(s) expiré(s) clôturé(s).')
        ->assertSuccessful();

    foreach ([$expired, $expiringNow] as $project) {
        $project->refresh();
        expect($project->status)->toBe(ProjectStatus::Closed)
            ->and($project->closed_at?->toIso8601ZuluString())->toBe('2026-11-10T03:00:00Z');
    }

    expect($live->fresh()->status)->toBe(ProjectStatus::Published)
        ->and($draft->fresh()->status)->toBe(ProjectStatus::Draft)
        ->and($closed->fresh()->closed_at?->toIso8601ZuluString())->toBe('2026-11-07T03:00:00Z');
});

it('peut être relancée sans effet', function (): void {
    Project::factory()->expired()->create();

    $this->artisan('projects:close-expired')->expectsOutput('1 projet(s) expiré(s) clôturé(s).')->assertSuccessful();
    $this->artisan('projects:close-expired')->expectsOutput('0 projet(s) expiré(s) clôturé(s).')->assertSuccessful();
});

it('laisse le propriétaire republier un projet clôturé automatiquement', function (): void {
    $user = projectOwner('particulier');
    $project = Project::factory()->for($user)->expired()->create();

    $this->artisan('projects:close-expired')->assertSuccessful();

    $this->actingAs($user)
        ->postJson("/api/v1/me/projects/{$project->uuid}/publish")
        ->assertOk()
        ->assertJsonPath('data.status', 'published');
});
