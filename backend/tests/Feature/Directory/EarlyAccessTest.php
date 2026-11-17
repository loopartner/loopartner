<?php

use App\Models\User;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/*
| Accès anticipé aux nouveaux projets (décisions 33 et 44) : pendant 24 heures après sa
| publication, un projet n'est visible que des prestataires Premium (liste, détail, proposition,
| signalement), puis de tous. L'ordre des résultats est le même pour tous.
|
| phpunit.xml désactive la fenêtre pour le reste de la suite : on la remet ici à la valeur de
| production.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-10 12:00:00');
    Notification::fake();
    config(['directory.early_access_hours' => 24]);
});

function earlyAccessProject(string $title, Carbon $publishedAt): Project
{
    return Project::factory()->published($publishedAt)->create([
        'title' => $title,
        'user_id' => projectOwner('particulier')->id,
    ]);
}

function premiumBrowsingProvider(): User
{
    $provider = browsingProvider();
    makePremium($provider);

    return $provider;
}

describe('prestataire sans Premium', function (): void {
    it('ne voit un projet qu\'après 24 heures, dans la liste comme dans le détail', function (): void {
        $fresh = earlyAccessProject('Nouveau', now()->subHours(23));
        earlyAccessProject('Ancien', now()->subHours(25));
        $provider = browsingProvider();

        $this->actingAs($provider)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ancien');
        $this->actingAs($provider)->getJson("/api/v1/projects/{$fresh->uuid}")->assertNotFound();

        $this->travel(2)->hours();

        $this->actingAs($provider)->getJson("/api/v1/projects/{$fresh->uuid}")->assertOk();
        $this->actingAs($provider)->getJson('/api/v1/projects')->assertJsonCount(2, 'data');
    });

    it('ne peut ni proposer ses services ni signaler un projet encore réservé (404)', function (): void {
        $fresh = earlyAccessProject('Nouveau', now()->subHour());
        $provider = browsingProvider();

        $this->actingAs($provider)->postJson("/api/v1/projects/{$fresh->uuid}/proposals", proposalPayload())->assertNotFound();
        $this->actingAs($provider)->postJson('/api/v1/moderation/notices', [
            'target_type' => 'project',
            'target_ref' => $fresh->uuid,
            'category' => 'scam_fraud',
            'explanation' => 'Ce projet promet des gains garantis et renvoie vers un site frauduleux.',
            'good_faith' => true,
        ])->assertNotFound();
    });

    it('voit de nouveau le projet en entier après une republication, une fois la fenêtre passée', function (): void {
        $project = earlyAccessProject('Republié', now()->subDays(3));
        $provider = browsingProvider();
        $project->forceFill(['published_at' => now()->subMinutes(10), 'expires_at' => now()->addDays(60)])->save();

        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")->assertNotFound();
    });
});

describe('prestataire Premium', function (): void {
    it('voit tout de suite les nouveaux projets, dans le même ordre que les autres', function (): void {
        earlyAccessProject('Ancien', now()->subDays(2));
        earlyAccessProject('Nouveau', now()->subHour());
        $premium = premiumBrowsingProvider();

        $this->actingAs($premium)->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.*.title', ['Nouveau', 'Ancien']);
    });

    it('peut proposer ses services pendant la fenêtre', function (): void {
        $fresh = earlyAccessProject('Nouveau', now()->subHour());

        $this->actingAs(premiumBrowsingProvider())
            ->postJson("/api/v1/projects/{$fresh->uuid}/proposals", proposalPayload())
            ->assertCreated();
    });

    it('perd l\'accès anticipé à la fin de sa période Premium', function (): void {
        $fresh = earlyAccessProject('Nouveau', now()->subHour());
        $provider = browsingProvider();
        makePremium($provider, ['starts_at' => now()->subMonth(), 'ends_at' => now()->subMinute()]);

        $this->actingAs($provider)->getJson("/api/v1/projects/{$fresh->uuid}")->assertNotFound();
    });
});

describe('porteur du projet', function (): void {
    it('voit la fin de la fenêtre d\'accès anticipé de son projet publié', function (): void {
        $owner = projectOwner('particulier');
        $project = Project::factory()->published(now()->subHour())->create(['user_id' => $owner->id]);
        $draft = Project::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.early_access_ends_at', '2027-03-11T11:00:00Z');
        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$draft->uuid}")
            ->assertJsonPath('data.early_access_ends_at', null);
    });

    it('ne voit aucune fenêtre quand l\'accès anticipé est désactivé', function (): void {
        config(['directory.early_access_hours' => 0]);
        $owner = projectOwner('particulier');
        $project = Project::factory()->published(now()->subHour())->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->getJson("/api/v1/me/projects/{$project->uuid}")->assertJsonPath('data.early_access_ends_at', null);
        $this->actingAs(browsingProvider())->getJson("/api/v1/projects/{$project->uuid}")->assertOk();
    });
});
