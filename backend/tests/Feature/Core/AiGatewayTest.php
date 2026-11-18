<?php

use App\Models\AiUsage;
use App\Models\User;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiGateway;
use App\Support\Ai\AiPricing;
use App\Support\Ai\AiRequest;
use App\Support\Ai\Drivers\FakeAiClient;
use App\Support\Ai\Exceptions\AiBusy;
use App\Support\Ai\Exceptions\AiInvalidResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;
use App\Support\Ai\Exceptions\AiQuotaExceeded;
use App\Support\Ai\Exceptions\AiUnavailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| Brique d'IA du noyau (décisions 34, 62 et 70) : disponibilité, verrou par utilisateur,
| plafonds de coût réservés sous verrou, coût réel, journal sans contenu. Aucun appel réseau :
| pilote simulé (phpunit.xml) et Http::preventStrayRequests.
*/

const AI_TEST_SECRET = 'Contenu-confidentiel-du-projet-7731';

function aiTestRequest(int $maxOutputTokens = 1000): AiRequest
{
    return new AiRequest(
        feature: 'test.feature',
        promptVersion: 'v1',
        system: 'Consignes fixes.',
        content: json_encode(['project' => AI_TEST_SECRET]) ?: '{}',
        schemaName: 'test',
        schema: ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']],
        maxOutputTokens: $maxOutputTokens,
    );
}

function fakeAi(): FakeAiClient
{
    /** @var FakeAiClient */
    return app(AiClient::class);
}

function useAiDriver(string $driver): void
{
    config(['ai.driver' => $driver]);
    app()->forgetInstance(AiClient::class);
}

it('est désactivée par défaut et le reste sans appel quand le pilote est "disabled"', function (): void {
    expect(require config_path('ai.php'))->toHaveKey('driver');
    expect(env('AI_DRIVER'))->toBe('fake'); // tests seulement (phpunit.xml)

    useAiDriver('disabled');
    $gateway = app(AiGateway::class);

    expect($gateway->available())->toBeFalse()
        ->and($gateway->unavailableReason())->toBe('disabled')
        ->and($gateway->providerLabel())->toBeNull();

    expect(fn () => $gateway->generate(User::factory()->create(), aiTestRequest()))
        ->toThrow(fn (AiUnavailable $exception) => expect($exception->reason)->toBe('disabled'));
    expect(AiUsage::query()->count())->toBe(0);
});

it('lit la valeur "disabled" par défaut dans config/ai.php', function (): void {
    $file = (string) file_get_contents(config_path('ai.php'));

    expect($file)->toContain("'driver' => env('AI_DRIVER', 'disabled')");
});

it('refuse un modèle sans prix et un max_execution_time trop court', function (): void {
    config(['ai.pricing' => []]);
    expect(app(AiGateway::class)->unavailableReason())->toBe('pricing');

    config(['ai.pricing' => ['fake-model' => ['input' => 1, 'output' => 1]]]);
    $previous = ini_get('max_execution_time');

    try {
        ini_set('max_execution_time', '30');
        expect(app(AiGateway::class)->unavailableReason())->toBe('execution_time');
        ini_set('max_execution_time', '60');
        expect(app(AiGateway::class)->unavailableReason())->toBeNull();
    } finally {
        ini_set('max_execution_time', (string) $previous);
    }

    expect(AiGateway::executionTimeSufficient('0'))->toBeTrue()
        ->and(AiGateway::executionTimeSufficient(59))->toBeFalse()
        ->and(AiGateway::executionTimeSufficient(60))->toBeTrue();
});

it('appelle le pilote, enregistre le coût réel et journalise sans le contenu', function (): void {
    $user = User::factory()->create();
    fakeAi()->usage(3000, 2000)->respondWith(['answer' => 'Réponse générée']);

    $response = app(AiGateway::class)->generate($user, aiTestRequest());

    expect($response->data)->toBe(['answer' => 'Réponse générée']);

    $usage = AiUsage::query()->sole();
    $expected = AiPricing::cost('fake-model', 3000, 2000);
    expect($usage->status)->toBe(AiUsage::STATUS_SUCCEEDED)
        ->and($usage->user_id)->toBe($user->id)
        ->and($usage->input_tokens)->toBe(3000)
        ->and($usage->output_tokens)->toBe(2000)
        ->and($usage->cost_micros)->toBe($expected)
        ->and($expected)->toBe(1815); // 3000 × 0,165 $ + 2000 × 0,66 $ par million de jetons

    $budgets = DB::table('ai_budgets')->get();
    expect($budgets)->toHaveCount(3);
    foreach ($budgets as $budget) {
        expect((int) $budget->reserved_micros)->toBe(0)
            ->and((int) $budget->spent_micros)->toBe($expected);
    }

    $stats = DB::table('ai_usage_stats')->sole();
    expect((int) $stats->requests)->toBe(1)
        ->and((int) $stats->cost_micros)->toBe($expected)
        ->and((array) $stats)->not->toHaveKey('user_id');

    $log = json_encode(securityLog());
    expect(securityLog('ai.completed'))->toHaveCount(1)
        ->and($log)->not->toContain(AI_TEST_SECRET)
        ->and($log)->not->toContain('Réponse générée')
        ->and(json_encode(AiUsage::query()->get()))->not->toContain(AI_TEST_SECRET);
});

it('ne fait rien payer à l\'utilisateur quand le fournisseur échoue (503 provider)', function (): void {
    $user = User::factory()->create();
    fakeAi()->respondWith(new AiProviderFailure('timeout'));

    expect(fn () => app(AiGateway::class)->generate($user, aiTestRequest()))
        ->toThrow(fn (AiUnavailable $exception) => expect($exception->reason)->toBe('provider'));

    $usage = AiUsage::query()->sole();
    expect($usage->status)->toBe(AiUsage::STATUS_FAILED)
        ->and($usage->failure_reason)->toBe('timeout')
        ->and($usage->cost_micros)->toBe(0)
        ->and(DB::table('ai_budgets')->sum('reserved_micros'))->toEqual(0)
        ->and(DB::table('ai_budgets')->sum('spent_micros'))->toEqual(0)
        ->and(securityLog('ai.provider_failed'))->toHaveCount(1);
});

it('compte dans le budget les jetons d\'une réponse inexploitable et relance l\'erreur 502', function (): void {
    $user = User::factory()->create();
    fakeAi()->respondWith(new AiInvalidResponse('truncated', 3000, 3000));

    expect(fn () => app(AiGateway::class)->generate($user, aiTestRequest()))->toThrow(AiInvalidResponse::class);

    expect(AiUsage::query()->sole()->cost_micros)->toBe(AiPricing::cost('fake-model', 3000, 3000));
});

it('refuse avant tout appel quand le plafond mensuel de l\'utilisateur serait dépassé (429 budget)', function (): void {
    $this->travelTo('2027-03-14 10:00:00');
    $user = User::factory()->create();
    config(['ai.budgets.user_monthly_cents' => 1]); // 10 000 micros
    DB::table('ai_budgets')->insert(['scope_key' => 'user:'.$user->id, 'period' => '2027-03', 'user_id' => $user->id, 'spent_micros' => 9_900, 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => app(AiGateway::class)->generate($user, aiTestRequest()))
        ->toThrow(function (AiQuotaExceeded $exception): void {
            expect($exception->scope)->toBe('budget')
                ->and($exception->resetsAt->toIso8601ZuluString())->toBe('2027-04-01T00:00:00Z');
        });

    expect(fakeAi()->requests())->toBe([])
        ->and(AiUsage::query()->count())->toBe(0)
        ->and(securityLog('ai.budget_exceeded')[0]->context['scope'])->toBe('user');

    // Un autre utilisateur n'est pas concerné.
    app(AiGateway::class)->generate(User::factory()->create(), aiTestRequest());
    expect(fakeAi()->requests())->toHaveCount(1);
});

it('rend l\'IA indisponible pour tous quand le plafond global du mois ou du jour serait dépassé', function (string $setting): void {
    config(['ai.budgets.'.$setting => 1]);
    DB::table('ai_budgets')->insert([
        'scope_key' => $setting === 'global_daily_cents' ? 'global:day' : 'global:month',
        'period' => $setting === 'global_daily_cents' ? now('UTC')->format('Y-m-d') : now('UTC')->format('Y-m'),
        'spent_micros' => 9_999,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(fn () => app(AiGateway::class)->generate(User::factory()->create(), aiTestRequest()))
        ->toThrow(fn (AiUnavailable $exception) => expect($exception->reason)->toBe('capacity'));
    expect(fakeAi()->requests())->toBe([]);
})->with(['global_monthly_cents', 'global_daily_cents']);

it('réserve le coût maximal pendant l\'appel : la réservation compte pour l\'appel suivant', function (): void {
    $user = User::factory()->create();
    $request = aiTestRequest(maxOutputTokens: 10_000);
    $estimate = AiPricing::cost('fake-model', $request->estimatedInputTokens(), 10_000);
    expect($estimate)->toBeGreaterThan(5_000)->toBeLessThan(10_000);
    config(['ai.budgets.user_monthly_cents' => 1]); // 10 000 micros : un appel, pas deux

    // Un appel en cours (réservé, pas encore réglé) occupe déjà la place.
    DB::table('ai_budgets')->insert(['scope_key' => 'user:'.$user->id, 'period' => now('UTC')->format('Y-m'), 'user_id' => $user->id, 'reserved_micros' => $estimate, 'created_at' => now(), 'updated_at' => now()]);
    expect(fn () => app(AiGateway::class)->generate($user, $request))->toThrow(AiQuotaExceeded::class);

    DB::table('ai_budgets')->where('scope_key', 'user:'.$user->id)->update(['reserved_micros' => 0]);
    app(AiGateway::class)->generate($user, $request);
    expect(fakeAi()->requests())->toHaveCount(1);
});

it('refuse une deuxième génération simultanée du même utilisateur (409)', function (): void {
    $user = User::factory()->create();
    $lock = Cache::lock('ai:generation:'.$user->id, 60);
    $lock->get();

    try {
        expect(fn () => app(AiGateway::class)->generate($user, aiTestRequest()))->toThrow(AiBusy::class);
    } finally {
        $lock->release();
    }

    app(AiGateway::class)->generate($user, aiTestRequest());
    expect(fakeAi()->requests())->toHaveCount(1);
});

it('détache le journal des appels et supprime les compteurs à la suppression du compte', function (): void {
    $user = User::factory()->create();
    app(AiGateway::class)->generate($user, aiTestRequest());

    $user->delete();

    expect(AiUsage::query()->sole()->user_id)->toBeNull()
        ->and(DB::table('ai_budgets')->where('scope_key', 'like', 'user:%')->count())->toBe(0)
        ->and(DB::table('ai_budgets')->where('scope_key', 'global:month')->count())->toBe(1);
});

it('construit une réponse d\'exemple conforme au schéma demandé (pilote simulé)', function (): void {
    expect(FakeAiClient::example([
        'type' => 'object',
        'properties' => [
            'title' => ['type' => 'string'],
            'steps' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['n' => ['type' => 'integer']]]],
            'kind' => ['type' => 'string', 'enum' => ['a', 'b']],
            'category' => ['type' => ['string', 'null']],
        ],
    ]))->toBe([
        'title' => 'Exemple de texte généré',
        'steps' => [['n' => 1]],
        'kind' => 'a',
        'category' => 'Exemple de texte généré',
    ]);
});
