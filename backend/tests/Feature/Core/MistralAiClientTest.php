<?php

use App\Models\AiUsage;
use App\Support\Ai\AiClient;
use App\Support\Ai\AiGateway;
use App\Support\Ai\AiRequest;
use App\Support\Ai\Drivers\MistralAiClient;
use App\Support\Ai\Exceptions\AiInvalidResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;
use App\Support\Ai\Exceptions\AiUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Pilote Mistral AI (décision 60) : endpoint régional de l'UE imposé, corps de requête
| exact, lecture défensive de la réponse. Testé uniquement avec de fausses réponses
| (Http::fake) : aucun appel réseau réel.
*/

const MISTRAL_TEST_KEY = 'cle-secrete-de-test-ne-jamais-journaliser';
const MISTRAL_EU_URL = 'https://api.eu.mistral.ai/v1/chat/completions';

function mistralClient(string $baseUrl = 'https://api.eu.mistral.ai', ?string $key = MISTRAL_TEST_KEY): MistralAiClient
{
    config([
        'ai.driver' => 'mistral',
        'ai.model' => 'mistral-small-2603',
        'ai.providers.mistral.api_key' => $key,
        'ai.providers.mistral.base_url' => $baseUrl,
    ]);
    app()->forgetInstance(AiClient::class);

    /** @var MistralAiClient */
    return app(AiClient::class);
}

function mistralRequest(): AiRequest
{
    return new AiRequest(
        feature: 'copilot.roadmap',
        promptVersion: 'v1',
        system: 'Consignes.',
        content: '{"project":"Contenu-privé-du-projet"}',
        schemaName: 'copilot_roadmap',
        schema: ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary'], 'additionalProperties' => false],
        maxOutputTokens: 3000,
    );
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mistralAnswer(mixed $content = '{"summary":"Feuille de route"}', string $finish = 'stop', array $overrides = []): array
{
    return [
        'id' => 'cmpl-1',
        'object' => 'chat.completion',
        'model' => 'mistral-small-2603',
        'created' => 1,
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $content], 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => 2800, 'completion_tokens' => 1900, 'total_tokens' => 4700],
        ...$overrides,
    ];
}

it('envoie à l\'endpoint de l\'UE exactement les consignes, le contenu et le schéma, et lit la réponse', function (): void {
    Http::fake([MISTRAL_EU_URL => Http::response(mistralAnswer())]);

    $response = mistralClient()->generate(mistralRequest());

    expect($response->data)->toBe(['summary' => 'Feuille de route'])
        ->and($response->inputTokens)->toBe(2800)
        ->and($response->outputTokens)->toBe(1900)
        ->and($response->model)->toBe('mistral-small-2603');

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        expect($request->url())->toBe(MISTRAL_EU_URL)
            ->and($request->method())->toBe('POST')
            ->and($request->header('Authorization'))->toBe(['Bearer '.MISTRAL_TEST_KEY])
            ->and(array_keys($request->data()))->toBe(['model', 'messages', 'max_tokens', 'temperature', 'reasoning_effort', 'response_format'])
            ->and($request['model'])->toBe('mistral-small-2603')
            ->and($request['messages'])->toBe([
                ['role' => 'system', 'content' => 'Consignes.'],
                ['role' => 'user', 'content' => '{"project":"Contenu-privé-du-projet"}'],
            ])
            ->and($request['reasoning_effort'])->toBe('none')
            ->and($request['response_format']['type'])->toBe('json_schema')
            ->and($request['response_format']['json_schema']['strict'])->toBeTrue()
            ->and($request['response_format']['json_schema']['name'])->toBe('copilot_roadmap');

        return true;
    });
});

it('refuse tout autre endpoint que celui de l\'UE, sans rien envoyer', function (string $baseUrl): void {
    Http::fake();
    $client = mistralClient($baseUrl);

    expect($client->enabled())->toBeFalse()
        ->and(app(AiGateway::class)->unavailableReason())->toBe('disabled');
    expect(fn () => $client->generate(mistralRequest()))->toThrow(AiProviderFailure::class);

    Http::assertNothingSent();
})->with([
    'endpoint global' => 'https://api.mistral.ai',
    'http' => 'http://api.eu.mistral.ai',
    'autre hôte' => 'https://api.eu.mistral.ai.example.com',
    'chemin' => 'https://api.eu.mistral.ai/proxy',
    'identifiants' => 'https://user:pass@api.eu.mistral.ai',
    'utilisateur' => 'https://user@api.eu.mistral.ai',
    'port' => 'https://api.eu.mistral.ai:8443',
]);

it('reste désactivé sans clé', function (): void {
    Http::fake();

    expect(mistralClient(key: '')->enabled())->toBeFalse()
        ->and(mistralClient(key: null)->enabled())->toBeFalse();
});

it('lit une réponse en morceaux en ignorant la trace de raisonnement', function (): void {
    Http::fake([MISTRAL_EU_URL => Http::response(mistralAnswer([
        ['type' => 'thinking', 'thinking' => [['type' => 'text', 'text' => 'raisonnement']]],
        ['type' => 'text', 'text' => '{"summary":"Morceaux"}'],
    ]))]);

    expect(mistralClient()->generate(mistralRequest())->data)->toBe(['summary' => 'Morceaux']);
});

it('rejette une réponse tronquée, vide ou qui n\'est pas un objet JSON, en gardant les jetons', function (array $answer, string $reason): void {
    Http::fake([MISTRAL_EU_URL => Http::response($answer)]);

    expect(fn () => mistralClient()->generate(mistralRequest()))
        ->toThrow(function (AiInvalidResponse $exception) use ($reason): void {
            expect($exception->reason)->toBe($reason)
                ->and($exception->getMessage())->not->toContain('summary');
        });
})->with([
    'tronquée' => [mistralAnswer('{"summary":"coup', 'length'), 'truncated'],
    'texte libre' => [mistralAnswer('Voici votre feuille de route'), 'invalid_json'],
    'liste' => [mistralAnswer('["a"]'), 'invalid_json'],
    'sans choix' => [mistralAnswer(overrides: ['choices' => []]), 'no_choice'],
]);

it('traduit les erreurs du fournisseur sans reprendre le corps ni la clé', function (int $status): void {
    Http::fake([MISTRAL_EU_URL => Http::response(['message' => 'Clé '.MISTRAL_TEST_KEY.' refusée'], $status)]);

    expect(fn () => mistralClient()->generate(mistralRequest()))
        ->toThrow(function (AiProviderFailure $failure) use ($status): void {
            expect($failure->reason)->toBe('http_'.$status)
                ->and($failure->getMessage())->not->toContain(MISTRAL_TEST_KEY);
        });
})->with([401, 429, 500, 503]);

it('traduit une coupure réseau en indisponibilité (503 provider) sans rien journaliser du contenu', function (): void {
    $user = workspaceOwner();
    mistralClient();
    Http::fake([MISTRAL_EU_URL => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

    expect(fn () => app(AiGateway::class)->generate($user, mistralRequest()))
        ->toThrow(fn (AiUnavailable $exception) => expect($exception->reason)->toBe('provider'));

    $log = json_encode(securityLog());
    expect(securityLog('ai.provider_failed')[0]->context['reason'])->toBe('timeout')
        ->and($log)->not->toContain(MISTRAL_TEST_KEY)
        ->and($log)->not->toContain('Contenu-privé-du-projet');
});

it('passe par la passerelle de bout en bout et compte le coût réel au prix du modèle', function (): void {
    $user = workspaceOwner();
    mistralClient();
    Http::fake([MISTRAL_EU_URL => Http::response(mistralAnswer())]);

    app(AiGateway::class)->generate($user, mistralRequest());

    expect(AiUsage::query()->sole()->cost_micros)->toBe(1716) // 2800 × 0,165 $ + 1900 × 0,66 $ par million
        ->and(AiUsage::query()->sole()->provider)->toBe('mistral');
});

it('teste la connexion avec une requête fixe, sans donnée d\'utilisateur', function (): void {
    mistralClient();
    Http::fake([MISTRAL_EU_URL => Http::response(mistralAnswer('{"word":"bonjour","number":3}'))]);

    $this->artisan('ai:test-connection')
        ->expectsOutputToContain('Connexion réussie')
        ->expectsOutputToContain('2800 en entrée')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request): bool => str_contains((string) $request['messages'][1]['content'], 'bonjour'));
});

it('refuse de tester une IA désactivée', function (): void {
    config(['ai.driver' => 'disabled']);
    app()->forgetInstance(AiClient::class);

    $this->artisan('ai:test-connection')->expectsOutputToContain('pas activée')->assertFailed();
});
