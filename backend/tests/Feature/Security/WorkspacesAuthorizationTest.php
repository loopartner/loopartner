<?php

use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Specification;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use App\Modules\Workspaces\Support\CopilotTexts;
use App\Support\Ai\AiClient;
use App\Support\Ai\Drivers\FakeAiClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
| Workspaces et copilote : autorisation de CHAQUE route du module, lue dans le routeur, contre
| chaque rôle et un autre utilisateur (OWASP API1 BOLA, API5 BFLA). Une nouvelle route du
| module est testée sans rien ajouter ici.
|
| - Route qui vise un workspace : 404 pour tout autre particulier ou entrepreneur, 403 pour
|   les autres rôles (admins compris : ils n'ont aucun accès aux workspaces), et aucune donnée
|   modifiée ni aucun appel à l'IA.
| - Aucune route du module n'est publique.
*/

/**
 * Workspace complet d'une victime : [uuids de la route].
 *
 * @return array<string, string>
 */
function workspaceVictim(): array
{
    serviceCategory('site-vitrine');
    $owner = workspaceOwner();
    $workspace = workspaceOf($owner, ['title' => 'Workspace de la victime']);
    $step = Step::factory()->for($workspace)->create();
    $task = Task::factory()->for($step)->create();
    $goal = Goal::factory()->for($workspace)->create();
    $specification = (new Specification)->forceFill(['uuid' => (string) Str::uuid(), 'workspace_id' => $workspace->id, 'title' => 'Cahier', 'sections' => []]);
    $specification->save();
    $suggestion = test()->actingAs($owner)->postJson('/api/v1/me/workspaces/'.$workspace->uuid.'/copilot/roadmap', ['notice_version' => CopilotTexts::NOTICE_VERSION])->assertCreated()->json('data.uuid');

    return [
        '{uuid}' => $workspace->uuid,
        '{step}' => $step->uuid,
        '{task}' => $task->uuid,
        '{goal}' => $goal->uuid,
        '{specification}' => $specification->uuid,
        '{suggestion}' => (string) $suggestion,
    ];
}

/**
 * Routes du module qui visent un workspace : [méthode, uri].
 *
 * @return list<array{0: string, 1: string}>
 */
function workspaceObjectRoutes(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'workspaces.') && str_contains($route->uri(), '{uuid}'))
        ->flatMap(fn ($route): array => array_map(fn (string $method): array => [$method, $route->uri()], array_values(array_diff($route->methods(), ['HEAD']))))
        ->values()
        ->all();
}

function workspacesSnapshot(): string
{
    return (string) json_encode([
        DB::table('workspaces')->orderBy('id')->get(['id', 'status', 'activated_at', 'linked_project_id', 'updated_at']),
        DB::table('workspace_goals')->orderBy('id')->get(['id', 'updated_at']),
        DB::table('workspace_steps')->orderBy('id')->get(['id', 'position', 'updated_at']),
        DB::table('workspace_tasks')->orderBy('id')->get(['id', 'step_id', 'position', 'updated_at']),
        DB::table('workspace_specifications')->orderBy('id')->get(['id', 'updated_at']),
        DB::table('workspace_copilot_suggestions')->orderBy('id')->get(['id', 'status']),
        DB::table('workspace_copilot_entries')->count(),
    ]);
}

/**
 * Corps valide pour chaque écriture (pour qu'une erreur ne masque pas un défaut d'autorisation).
 *
 * @param  array<string, string>  $refs
 * @return array<string, mixed>
 */
function workspaceRouteBody(string $uri, array $refs): array
{
    return match (true) {
        str_ends_with($uri, '/order') && str_contains($uri, '/tasks/') => ['order' => [$refs['{task}']]],
        str_ends_with($uri, '/goals/order') => ['order' => [$refs['{goal}']]],
        str_ends_with($uri, '/steps/order') => ['order' => [$refs['{step}']]],
        str_ends_with($uri, '/roadmap'), str_ends_with($uri, '/specification') => ['notice_version' => CopilotTexts::NOTICE_VERSION],
        str_contains($uri, '/specifications') => ['title' => 'Cahier modifié', 'sections' => ['context' => 'x']],
        str_ends_with($uri, '/linked-project') => ['project' => (string) Str::uuid()],
        str_ends_with($uri, '/directory-draft') => ['category' => 'site-vitrine'],
        default => ['title' => 'Titre modifié'],
    };
}

it('répond 404 à tout autre particulier ou entrepreneur, et 403 aux autres rôles, sur chaque route d\'un workspace', function (string $role): void {
    $refs = workspaceVictim();
    $before = workspacesSnapshot();
    $calls = count(app(AiClient::class) instanceof FakeAiClient ? app(AiClient::class)->requests() : []);
    $intruder = in_array($role, ['particulier', 'entrepreneur'], true) ? workspaceOwner($role) : projectOwner($role);
    makePremium($intruder);
    $expected = in_array($role, ['particulier', 'entrepreneur'], true) ? 404 : 403;
    $routes = workspaceObjectRoutes();

    expect(count($routes))->toBeGreaterThan(30);

    foreach ($routes as [$method, $uri]) {
        $url = '/'.strtr($uri, $refs);
        $response = $this->actingAs($intruder)->json($method, $url, $method === 'GET' ? [] : workspaceRouteBody($uri, $refs));

        expect($response->status())->toBe($expected, "{$method} {$uri}");
        expect((string) $response->getContent())->not->toContain('Workspace de la victime');
    }

    /** @var FakeAiClient $ai */
    $ai = app(AiClient::class);
    expect(workspacesSnapshot())->toBe($before)
        ->and(count($ai->requests()))->toBe($calls);
})->with(['particulier', 'entrepreneur', 'prestataire', 'redacteur', 'admin']);

it('ne déclare aucune route publique dans le module', function (): void {
    $public = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->getName(), 'workspaces.'))
        ->reject(fn ($route): bool => in_array('auth:sanctum', $route->gatherMiddleware(), true));

    expect($public->map(fn ($route): string => $route->uri())->values()->all())->toBe([]);
});

it('refuse les routes sans propriétaire aux rôles qui n\'utilisent pas les workspaces', function (string $role): void {
    $user = projectOwner($role);

    $this->actingAs($user)->getJson('/api/v1/me/workspaces')->assertForbidden();
    $this->actingAs($user)->postJson('/api/v1/me/workspaces', ['title' => 'Projet'])->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/workspace-templates')->assertForbidden();
    $this->actingAs($user)->getJson('/api/v1/me/copilot')->assertForbidden();
})->with(['prestataire', 'redacteur', 'admin']);

it('n\'expose jamais d\'identifiant interne dans les réponses du module', function (): void {
    serviceCategory('site-vitrine');
    $user = workspaceOwner();
    $workspace = workspaceOf($user);
    Step::factory()->for($workspace)->create();

    foreach (['/api/v1/me/workspaces', '/api/v1/me/workspaces/'.$workspace->uuid, '/api/v1/me/copilot', '/api/v1/me/workspaces/'.$workspace->uuid.'/copilot/preview'] as $url) {
        $body = (string) $this->actingAs($user)->getJson($url)->assertOk()->getContent();

        expect($body)->not->toContain('"id"')
            ->and($body)->not->toContain('"user_id"')
            ->and($body)->not->toContain('"workspace_id"')
            ->and($body)->not->toContain('"service_category_id"')
            ->and($body)->not->toContain($user->email);
    }
});
