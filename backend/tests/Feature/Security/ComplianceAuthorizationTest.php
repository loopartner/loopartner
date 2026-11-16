<?php

use App\Models\User;
use App\Modules\Compliance\Actions\ManageJourney;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
| Suivi administratif : autorisation de CHAQUE route du module, contre chaque rôle et un autre
| utilisateur (OWASP API1 BOLA, API5 BFLA). Les routes sont lues dans le routeur : une nouvelle
| route du module est testée sans rien ajouter ici.
|
| - Route qui vise une entreprise (paramètre {company}) : 404 pour tout autre utilisateur,
|   admins et modérateurs compris, et aucune donnée modifiée.
| - Autre route du suivi : 403 pour les rôles qui n'y ont pas accès (particulier, rédacteur,
|   admin).
| - Administration des règles : 404 pour tout rôle autre qu'admin.
*/

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-13 10:00:00');
    Notification::fake();
    verifiedLegalForm();
    verifiedDeadlineRule('fr.test.cfe', ['type' => 'yearly', 'month' => 12, 'day' => 15], [], [
        ['key' => 'payer', 'title' => ['fr' => 'Payer', 'en' => null], 'service_category' => null],
    ]);
    ComplianceRule::factory()->verified()->journey('fr.journey.creer', [
        ['key' => 'etape', 'title' => ['fr' => 'Étape', 'en' => null], 'description' => null, 'service_category' => null, 'source_url' => null],
    ])->create();
});

/**
 * Routes du module : [méthode, uri] (sans HEAD).
 *
 * @return list<array{0: string, 1: string}>
 */
function complianceRoutes(string $filter): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), $filter))
        ->flatMap(fn ($route): array => array_map(fn (string $method): array => [$method, $route->uri()], array_values(array_diff($route->methods(), ['HEAD']))))
        ->values()
        ->all();
}

/**
 * Entreprise d'une victime avec une échéance, une tâche, un parcours et du chiffre d'affaires.
 *
 * @return array{company: Company, uris: array<string, string>}
 */
function victimCompany(): array
{
    $company = complianceCompanyOf();
    $journey = app(ManageJourney::class)->start($company, 'fr.journey.creer');
    $deadline = Deadline::query()->where('company_id', $company->id)->firstOrFail();
    $task = Task::query()->where('company_id', $company->id)->whereNotNull('deadline_id')->firstOrFail();

    return ['company' => $company, 'uris' => [
        '{company}' => $company->uuid,
        '{deadline}' => $deadline->uuid,
        '{task}' => $task->uuid,
        '{journey}' => $journey->uuid,
        '{period}' => '2026-10',
        '{country}' => 'FR',
    ]];
}

/**
 * Empreinte des données de la victime, pour vérifier qu'aucune n'a changé.
 */
function complianceSnapshot(): string
{
    return json_encode([
        DB::table('compliance_companies')->orderBy('id')->get(['id', 'updated_at']),
        DB::table('compliance_deadlines')->orderBy('id')->get(['id', 'status', 'due_on', 'updated_at']),
        DB::table('compliance_tasks')->orderBy('id')->get(['id', 'completed_at', 'updated_at']),
        DB::table('compliance_journeys')->orderBy('id')->get(['id', 'completed_at']),
        DB::table('compliance_revenue_entries')->count(),
    ]);
}

it('cache chaque route d\'une entreprise derrière un 404 pour tout autre utilisateur, admins compris', function (string $role): void {
    ['uris' => $uris] = victimCompany();
    $before = complianceSnapshot();
    $other = match ($role) {
        'moderateur' => moderator(),
        default => complianceUser($role),
    };
    makePremium($other);

    $routes = complianceRoutes('me/compliance/companies/{company}');
    expect(count($routes))->toBeGreaterThan(20);

    foreach ($routes as [$method, $uri]) {
        $response = fromFrontend()->actingAs($other)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->json($method, '/'.strtr($uri, $uris), [
                'name' => 'Piratée', 'title' => 'Piratée', 'notes' => 'Piratée', 'due_on' => '2027-01-01',
                'lines' => ['services_commercial' => 1], 'journey' => 'fr.journey.creer',
            ]);

        expect($response->status())->toBe(404, "{$method} {$uri} répond {$response->status()}");
    }

    expect(complianceSnapshot())->toBe($before);
})->with(['entrepreneur', 'prestataire', 'particulier', 'redacteur', 'admin', 'moderateur']);

it('refuse les autres routes du suivi aux rôles qui n\'y ont pas accès (403)', function (string $role): void {
    $user = $role === 'moderateur' ? moderator() : complianceUser($role);

    $routes = array_filter(
        [...complianceRoutes('me/compliance'), ...complianceRoutes('compliance/countries')],
        fn (array $route): bool => ! str_contains($route[1], '{company}'),
    );
    expect(count($routes))->toBeGreaterThan(8);

    foreach ($routes as [$method, $uri]) {
        $response = $this->actingAs($user)->json($method, '/'.strtr($uri, ['{country}' => 'FR']), []);

        expect($response->status())->toBe(403, "{$method} {$uri} répond {$response->status()}");
    }

    expect(Company::query()->count())->toBe(0)
        ->and(DB::table('compliance_calendar_feeds')->count())->toBe(0);
})->with(['particulier', 'redacteur', 'admin', 'moderateur']);

it('refuse tout le suivi à un prestataire ou un entrepreneur sans email vérifié (403)', function (string $role): void {
    $user = complianceUser($role, verified: false);

    foreach ([...complianceRoutes('me/compliance'), ...complianceRoutes('compliance/countries')] as [$method, $uri]) {
        $response = $this->actingAs($user)->json($method, '/'.strtr($uri, ['{company}' => '3f2504e0-4f89-41d3-9a0c-0305e82c3301', '{deadline}' => '7c9e6679-7425-40de-944b-e07fc1f90ae7', '{task}' => '16fd2706-8baf-433b-82eb-8c7fada847da', '{journey}' => 'a8098c1a-f86e-11da-bd1a-00112444be1e', '{period}' => '2026-10', '{country}' => 'FR']));

        expect($response->status())->toBe(403, "{$method} {$uri}");
    }
})->with(['entrepreneur', 'prestataire']);

it('cache l\'administration des règles derrière un 404 pour tout rôle autre qu\'admin', function (string $role): void {
    $rule = ComplianceRule::query()->firstOrFail();
    $user = User::factory()->create();
    $user->assignRole($role);
    $before = ComplianceRule::query()->orderBy('id')->get()->toJson();

    foreach (complianceRoutes('admin/compliance') as [$method, $uri]) {
        $response = $this->actingAs($user)->json($method, '/'.strtr($uri, ['{uuid}' => $rule->uuid]), ['version' => 1, 'reason' => 'Retrait par un non-admin.', 'verification_note' => str_repeat('x', 40)]);

        expect($response->status())->toBe(404, "{$method} {$uri}");
    }

    expect(ComplianceRule::query()->orderBy('id')->get()->toJson())->toBe($before);
})->with(['particulier', 'prestataire', 'entrepreneur', 'redacteur']);
