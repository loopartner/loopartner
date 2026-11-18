<?php

use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Support\CopilotTexts;
use App\Support\Ai\AiClient;
use App\Support\Ai\Drivers\FakeAiClient;
use Illuminate\Support\Facades\DB;

/*
| Workspaces, copilote et IA : aucun contenu dans le journal security ni dans le journal des
| appels à l'IA (décision 34), sur tout le parcours (création, contenu, génération,
| application, cahier des charges, lien Directory, export de la prévisualisation).
*/

const WORKSPACE_PRIVATE_MARKERS = ['Titre-confidentiel-4821', 'Description-confidentielle-4821', 'Etape-confidentielle-4821', 'Note-confidentielle-4821', 'Reponse-IA-confidentielle-4821'];

it('n\'écrit aucun contenu de workspace ni aucun échange avec l\'IA dans les journaux', function (): void {
    serviceCategory('site-vitrine');
    $user = workspaceOwner();
    makePremium($user);
    /** @var FakeAiClient $ai */
    $ai = app(AiClient::class);
    $ai->respondWith(
        ['summary' => 'Reponse-IA-confidentielle-4821', 'steps' => [['title' => 'Reponse-IA-confidentielle-4821', 'description' => 'd', 'category' => 'site-vitrine', 'tasks' => ['t']]], 'categories' => []],
        ['title' => 'Reponse-IA-confidentielle-4821', 'sections' => ['context' => 'Reponse-IA-confidentielle-4821']],
    );

    $uuid = $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Titre-confidentiel-4821', 'description' => 'Description-confidentielle-4821 avec un texte assez long.', 'country' => 'FR'])->assertCreated()->json('data.uuid');
    $base = '/api/v1/me/workspaces/'.$uuid;
    $step = $this->actingAs($user)->postJson($base.'/steps', ['title' => 'Etape-confidentielle-4821', 'category' => 'site-vitrine'])->json('data.uuid');
    $this->actingAs($user)->postJson($base.'/steps/'.$step.'/tasks', ['title' => 'Tâche', 'notes' => 'Note-confidentielle-4821'])->assertCreated();
    $this->actingAs($user)->getJson($base.'/copilot/preview')->assertOk();
    $suggestion = $this->actingAs($user)->postJson($base.'/copilot/roadmap', ['notice_version' => CopilotTexts::NOTICE_VERSION])->assertCreated()->json('data.uuid');
    $this->actingAs($user)->postJson($base.'/copilot/suggestions/'.$suggestion.'/apply')->assertOk();
    $this->actingAs($user)->postJson($base.'/copilot/specification', ['notice_version' => CopilotTexts::NOTICE_VERSION, 'step' => $step])->assertCreated();
    $this->actingAs($user)->postJson($base.'/directory-draft', ['category' => 'site-vitrine'])->assertCreated();

    $log = json_encode(securityLog());
    $usages = json_encode([DB::table('ai_usages')->get(), DB::table('ai_usage_stats')->get(), DB::table('ai_budgets')->get(), DB::table('workspace_copilot_entries')->get()]);

    foreach (WORKSPACE_PRIVATE_MARKERS as $marker) {
        expect($log)->not->toContain($marker)
            ->and($usages)->not->toContain($marker);
    }

    // Au repos, les textes sont chiffrés.
    $tables = json_encode([DB::table('workspaces')->get(), DB::table('workspace_steps')->get(), DB::table('workspace_tasks')->get(), DB::table('workspace_copilot_suggestions')->get(), DB::table('workspace_specifications')->get()]);
    foreach (WORKSPACE_PRIVATE_MARKERS as $marker) {
        expect($tables)->not->toContain($marker);
    }

    expect(securityLog('copilot.generated'))->toHaveCount(2)
        ->and(securityLog('ai.completed'))->toHaveCount(2)
        ->and(Step::query()->count())->toBe(2);
});
