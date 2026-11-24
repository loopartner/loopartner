<?php

use App\Models\User;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Facades\Notification;

/*
| Décision 81 : le détail des projets (GET /projects, GET /projects/{uuid}) et les propositions
| sont réservés aux prestataires Premium. Sans Premium, le prestataire garde la vitrine publique.
|
| phpunit.xml ouvre cet accès pour le reste de la suite (DIRECTORY_FREE_PROJECT_ACCESS) : on le
| remet ici à la valeur de production.
*/

beforeEach(function (): void {
    Notification::fake();
    config(['directory.free_project_access' => false]);
});

function premiumProjectProvider(): User
{
    $provider = browsingProvider();
    makePremium($provider);

    return $provider;
}

describe('prestataire sans Premium', function (): void {
    it('reçoit 403 premium_required sur la liste et le détail des projets', function (): void {
        $project = publishedProjectOf();
        $provider = browsingProvider();

        $this->actingAs($provider)->getJson('/api/v1/projects')
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', 'project_access');

        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required')
            ->assertJsonPath('feature', 'project_access')
            ->assertJsonMissing(['title' => $project->title]);
    });

    it('ne peut pas faire de proposition, et rien n\'est créé', function (): void {
        $project = publishedProjectOf();

        $this->actingAs(browsingProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required');

        expect(MatchRequest::query()->count())->toBe(0);
    });

    it('garde la vitrine publique des projets', function (): void {
        $project = publishedProjectOf();

        $this->actingAs(browsingProvider())->getJson("/api/v1/public/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $project->uuid);
    });

    it('perd l\'accès quand sa période Premium est terminée', function (): void {
        $project = publishedProjectOf();
        $provider = browsingProvider();
        makePremium($provider, ['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);

        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")
            ->assertForbidden()
            ->assertJsonPath('code', 'premium_required');
    });

    it('reçoit d\'abord le message du profil à publier, avant toute question de Premium', function (): void {
        $user = projectOwner('prestataire');

        $this->actingAs($user)->getJson('/api/v1/projects')
            ->assertForbidden()
            ->assertJsonPath('message', 'Publiez votre profil prestataire, avec au moins une catégorie, pour consulter les projets.')
            ->assertJsonMissingPath('code');
    });

    it('ne change rien pour les autres rôles (403 sans code Premium)', function (): void {
        $this->actingAs(projectOwner('particulier'))->getJson('/api/v1/projects')
            ->assertForbidden()
            ->assertJsonMissingPath('code');
    });
});

describe('prestataire Premium', function (): void {
    it('consulte la liste et le détail des projets', function (): void {
        $project = publishedProjectOf();
        $provider = premiumProjectProvider();

        $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk()->assertJsonPath('data.0.uuid', $project->uuid);
        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")->assertOk();
    });

    it('fait une proposition', function (): void {
        $project = publishedProjectOf();

        $this->actingAs(premiumProjectProvider())
            ->postJson("/api/v1/projects/{$project->uuid}/proposals", proposalPayload())
            ->assertCreated();

        expect(MatchRequest::query()->count())->toBe(1);
    });

    it('reçoit toujours 404 pour un projet non publié', function (): void {
        $draft = Project::factory()->create();

        $this->actingAs(premiumProjectProvider())->getJson("/api/v1/projects/{$draft->uuid}")->assertNotFound();
    });
});

it('expose le droit dans les fonctionnalités du prestataire (gratuit : fermé)', function (): void {
    $this->actingAs(browsingProvider())->getJson('/api/v1/me/entitlements')
        ->assertOk()
        ->assertJsonPath('data.features', fn (array $features): bool => $features['directory.project_access'] === false);
});
