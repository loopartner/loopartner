<?php

use App\Modules\Accounts\Enums\ProviderProfileStatus;
use App\Modules\Accounts\Models\ProviderProfile;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;

/*
| Collecte massive des projets publiés : profil prestataire publié obligatoire, limite de débit
| dédiée et journal des consultations (canal "security", sans email ni nom).
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
});

describe('profil prestataire publié obligatoire', function (): void {
    it('refuse un prestataire sans profil publié avec au moins une catégorie (403)', function (Closure $makeProvider): void {
        $project = Project::factory()->published()->create();
        $provider = $makeProvider();

        $message = 'Publiez votre profil prestataire, avec au moins une catégorie, pour consulter les projets.';

        $this->actingAs($provider)->getJson('/api/v1/projects')
            ->assertForbidden()->assertJsonPath('message', $message);
        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")
            ->assertForbidden()->assertJsonPath('message', $message);
    })->with([
        'sans profil' => [fn () => projectOwner('prestataire')],
        'profil en brouillon' => [function () {
            $user = projectOwner('prestataire');
            $profile = ProviderProfile::factory()->create(['user_id' => $user->id, 'status' => ProviderProfileStatus::Draft]);
            $profile->categories()->attach(serviceCategory('design'));

            return $user;
        }],
        'profil publié sans catégorie' => [function () {
            $user = projectOwner('prestataire');
            ProviderProfile::factory()->published()->create(['user_id' => $user->id]);

            return $user;
        }],
    ]);

    it('autorise un prestataire dont le profil est publié avec une catégorie', function (): void {
        $project = Project::factory()->published()->create();
        $provider = browsingProvider();

        $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")->assertOk();
    });
});

describe('limite de débit', function (): void {
    it('applique 30 requêtes par minute et 500 par jour par défaut', function (): void {
        expect(config('security.rate_limits.projects_browse'))->toBe(['per_minute' => 30, 'per_day' => 500]);
    });

    it('limite la consultation par minute puis par jour, liste et détail confondus', function (): void {
        config(['security.rate_limits.projects_browse' => ['per_minute' => 30, 'per_day' => 40]]);
        $project = Project::factory()->published()->create();
        $provider = browsingProvider();

        for ($i = 0; $i < 30; $i++) {
            $url = $i % 2 === 0 ? '/api/v1/projects' : "/api/v1/projects/{$project->uuid}";
            $this->actingAs($provider)->getJson($url)->assertOk();
        }

        $this->actingAs($provider)->getJson('/api/v1/projects')->assertTooManyRequests();

        $this->travel(61)->seconds();
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($provider)->getJson("/api/v1/projects/{$project->uuid}")->assertOk();
        }

        // Quota du jour atteint, même après la fenêtre d'une minute.
        $this->travel(61)->seconds();
        $this->actingAs($provider)->getJson('/api/v1/projects')->assertTooManyRequests();

        // Un autre prestataire n'est pas concerné.
        $this->actingAs(browsingProvider())->getJson('/api/v1/projects')->assertOk();
    });
});

describe('journal des consultations', function (): void {
    it('journalise la liste et le détail avec l\'utilisateur, l\'IP et la route, sans email ni nom', function (): void {
        $project = Project::factory()->published()->create();
        $provider = browsingProvider();

        $this->actingAs($provider)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->getJson('/api/v1/projects?country=fr&page=2')
            ->assertOk();
        $this->actingAs($provider)
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->getJson("/api/v1/projects/{$project->uuid}")
            ->assertOk();

        [$list] = securityLog('projects.browse');
        [$view] = securityLog('projects.view');

        expect($list->level->getName())->toBe('INFO')
            ->and($list->context)->toMatchArray([
                'event' => 'projects.browse',
                'user_id' => $provider->id,
                'ip' => '203.0.113.7',
                'route' => 'GET /api/v1/projects',
                'page' => 2,
                'filters' => ['country' => 'FR'],
            ])
            ->and($list->context['at'])->toBe('2026-11-10T09:00:00Z')
            ->and($view->context)->toMatchArray([
                'event' => 'projects.view',
                'user_id' => $provider->id,
                'ip' => '203.0.113.7',
                'route' => "GET /api/v1/projects/{$project->uuid}",
                'project' => $project->uuid,
            ]);

        $logged = json_encode(array_map(fn ($record) => $record->toArray(), securityLog()));
        expect($logged)->not->toContain($provider->email)
            ->and($logged)->not->toContain($provider->name);
    });

    it('signale une seule fois un prestataire qui dépasse le seuil quotidien', function (): void {
        config(['security.scraping.projects_daily_threshold' => 3]);
        $provider = browsingProvider();

        foreach (range(1, 2) as $i) {
            $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk();
        }
        expect(securityLog('projects.scraping_suspected'))->toBe([]);

        foreach (range(1, 3) as $i) {
            $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk();
        }

        $alerts = securityLog('projects.scraping_suspected');
        expect($alerts)->toHaveCount(1)
            ->and($alerts[0]->level->getName())->toBe('WARNING')
            ->and($alerts[0]->context)->toMatchArray([
                'user_id' => $provider->id,
                'consultations_today' => 3,
                'threshold' => 3,
            ]);

        // Le compteur repart à zéro le lendemain.
        $this->travel(1)->days();
        foreach (range(1, 2) as $i) {
            $this->actingAs($provider)->getJson('/api/v1/projects')->assertOk();
        }
        expect(securityLog('projects.scraping_suspected'))->toHaveCount(1);
    });

    it('ne journalise pas une consultation refusée comme une consultation', function (): void {
        $this->actingAs(projectOwner('prestataire'))->getJson('/api/v1/projects')->assertForbidden();

        expect(securityLog('projects.browse'))->toBe([]);
    });
});
