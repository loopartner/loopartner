<?php

use App\Models\AiUsage;
use App\Models\OnboardingState;
use App\Modules\Accounts\Actions\DeleteAccount;
use App\Modules\Workspaces\Models\CopilotEntry;
use App\Modules\Workspaces\Models\CopilotSuggestion;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Models\Workspace;
use App\Modules\Workspaces\Support\CopilotTexts;
use App\Modules\Workspaces\Support\WorkspacesPersonalData;
use App\Support\Ai\AiUsagePersonalData;
use Illuminate\Support\Facades\DB;

/*
| Suppression de compte et export interne (décisions 59 et 65) : tout le contenu des
| workspaces disparaît avec le compte ; le journal des appels à l'IA est détaché ; les classes
| internes d'export rendent ses données en clair, sans celles d'un autre utilisateur.
*/

beforeEach(function (): void {
    serviceCategory('site-vitrine');
});

/**
 * Utilisateur avec un workspace complet et une génération du copilote.
 */
function workspaceWithEverything(): Workspace
{
    $user = workspaceOwner();
    $workspace = workspaceOf($user, ['title' => 'Projet exporté']);
    $step = Step::factory()->for($workspace)->create(['title' => 'Étape exportée']);
    Task::factory()->for($step)->create(['title' => 'Tâche exportée', 'notes' => 'Note exportée']);
    (new Specification)->forceFill(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'title' => 'Cahier exporté', 'sections' => ['context' => 'Contexte']])->save();
    test()->actingAs($user)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/copilot/roadmap', ['notice_version' => CopilotTexts::NOTICE_VERSION])->assertCreated();
    test()->actingAs($user)->postJson('/api/v1/me/onboarding/dismiss')->assertOk();

    return $workspace;
}

it('supprime tous les workspaces, leur contenu et le décompte des générations avec le compte', function (): void {
    $workspace = workspaceWithEverything();
    $other = workspaceOf(workspaceOwner());
    $user = $workspace->user;

    app(DeleteAccount::class)->handle($user->fresh());

    expect(Workspace::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Step::query()->count())->toBe(0)
        ->and(Task::query()->count())->toBe(0)
        ->and(Specification::query()->count())->toBe(0)
        ->and(CopilotSuggestion::query()->count())->toBe(0)
        ->and(CopilotEntry::query()->count())->toBe(0)
        ->and(OnboardingState::query()->count())->toBe(0)
        ->and(DB::table('ai_budgets')->where('scope_key', 'like', 'user:%')->count())->toBe(0)
        ->and(AiUsage::query()->sole()->user_id)->toBeNull();
});

it('rassemble les données personnelles du module pour l\'export, en clair et sans identifiant interne', function (): void {
    $workspace = workspaceWithEverything();
    workspaceOf(workspaceOwner(), ['title' => 'Workspace d\'un tiers']);

    $data = WorkspacesPersonalData::for($workspace->user);
    $core = AiUsagePersonalData::for($workspace->user);
    $json = json_encode([$data, $core]);

    expect($data['workspaces'])->toHaveCount(1)
        ->and($data['workspaces'][0]['title'])->toBe('Projet exporté')
        ->and($data['workspaces'][0]['steps'][0]['tasks'][0]['notes'])->toBe('Note exportée')
        ->and($data['workspaces'][0]['specifications'][0]['sections']['context'])->toBe('Contexte')
        ->and($data['workspaces'][0]['copilot_suggestions'])->toHaveCount(1)
        ->and($data['copilot_generations'][0]['kind'])->toBe('roadmap')
        ->and($core['ai_usages'])->toHaveCount(1)
        ->and($core['ai_usages'][0]['feature'])->toBe('copilot.roadmap')
        ->and($core['onboarding']['dismissed_at'])->toBeString()
        ->and($json)->not->toContain('tiers')
        ->and($json)->not->toContain('"id"')
        ->and($json)->not->toContain('"user_id"');
});
