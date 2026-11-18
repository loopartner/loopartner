<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiClient;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResponse;
use App\Support\Ai\Exceptions\AiInvalidResponse;
use App\Support\Ai\Exceptions\AiProviderFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Pilote Mistral AI (décision 60) : API Chat Completions de l'**endpoint régional de l'UE**
 * (https://api.eu.mistral.ai, traitement garanti dans l'UE), appelée avec le client Http de
 * Laravel (aucun paquet). Sortie imposée par un schéma JSON (response_format json_schema
 * strict), raisonnement réduit (reasoning_effort "none" : réponse sans trace de raisonnement).
 *
 * - Le pilote **refuse tout autre hôte** que l'endpoint de l'UE (en https) : il n'est alors
 *   pas activé et n'envoie rien.
 * - Ni la clé, ni le contenu envoyé, ni la réponse ne sont journalisés ou repris dans une
 *   exception (raisons courtes seulement : connection, timeout, http_<statut>).
 * - Une réponse tronquée (finish_reason "length"), vide ou qui n'est pas un objet JSON est
 *   inexploitable (AiInvalidResponse, jetons comptés dans les plafonds de coût).
 *
 * Format lu sur la documentation de Mistral le 2026-11-18 (docs/v1/workspaces-rules.md) ;
 * à confirmer au premier appel réel par php artisan ai:test-connection.
 */
final class MistralAiClient implements AiClient
{
    public const PATH = '/v1/chat/completions';

    public function __construct(
        #[SensitiveParameter] private ?string $apiKey,
        private string $baseUrl,
        private string $euHost,
        private string $model,
        private string $reasoningEffort,
        private float $temperature,
        private int $timeoutSeconds,
    ) {}

    public static function fromConfig(): self
    {
        $key = config('ai.providers.mistral.api_key');

        return new self(
            is_string($key) ? $key : null,
            (string) config('ai.providers.mistral.base_url'),
            (string) config('ai.providers.mistral.eu_host'),
            (string) config('ai.model'),
            (string) config('ai.providers.mistral.reasoning_effort'),
            (float) config('ai.providers.mistral.temperature'),
            (int) config('ai.timeout_seconds'),
        );
    }

    public function name(): string
    {
        return 'mistral';
    }

    public function providerLabel(): string
    {
        return (string) config('ai.providers.mistral.label');
    }

    public function enabled(): bool
    {
        return is_string($this->apiKey) && trim($this->apiKey) !== '' && $this->usesEuEndpoint();
    }

    /**
     * L'URL configurée est-elle exactement l'endpoint régional de l'UE, en https, sans
     * identifiants, port ni chemin ?
     */
    public function usesEuEndpoint(): bool
    {
        $parts = parse_url($this->baseUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === $this->euHost
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['port'])
            && in_array($parts['path'] ?? '', ['', '/'], true)
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    public function endpoint(): string
    {
        return 'https://'.$this->euHost.self::PATH;
    }

    public function generate(AiRequest $request): AiResponse
    {
        if (! $this->enabled()) {
            throw new AiProviderFailure('configuration');
        }

        try {
            $response = Http::withToken((string) $this->apiKey)
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout($this->timeoutSeconds)
                ->post($this->endpoint(), $this->body($request));
        } catch (ConnectionException $exception) {
            throw new AiProviderFailure(str_contains(strtolower($exception->getMessage()), 'timed out') ? 'timeout' : 'connection');
        }

        if (! $response->successful()) {
            throw new AiProviderFailure('http_'.$response->status());
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new AiInvalidResponse('not_json');
        }

        $inputTokens = (int) ($json['usage']['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($json['usage']['completion_tokens'] ?? 0);
        $choice = $json['choices'][0] ?? null;

        if (! is_array($choice)) {
            throw new AiInvalidResponse('no_choice', $inputTokens, $outputTokens);
        }

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new AiInvalidResponse('truncated', $inputTokens, $outputTokens);
        }

        $data = json_decode(self::text($choice['message']['content'] ?? null), true);

        if (! is_array($data) || array_is_list($data)) {
            throw new AiInvalidResponse('invalid_json', $inputTokens, $outputTokens);
        }

        return new AiResponse($data, $inputTokens, $outputTokens, is_string($json['model'] ?? null) ? $json['model'] : $this->model);
    }

    /**
     * Corps exact de la requête : consignes, contenu, schéma, plafond de sortie. Rien d'autre
     * (aucun identifiant d'utilisateur).
     *
     * @return array<string, mixed>
     */
    public function body(AiRequest $request): array
    {
        return [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $request->system],
                ['role' => 'user', 'content' => $request->content],
            ],
            'max_tokens' => $request->maxOutputTokens,
            'temperature' => $this->temperature,
            'reasoning_effort' => $this->reasoningEffort,
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => $request->schemaName,
                    'schema' => $request->schema,
                    'strict' => true,
                ],
            ],
        ];
    }

    /**
     * Texte de la réponse : chaîne, ou liste de morceaux dont seuls les morceaux "text" sont
     * gardés (la trace de raisonnement éventuelle est ignorée).
     */
    private static function text(mixed $content): string
    {
        if (is_string($content)) {
            return trim($content);
        }

        if (! is_array($content)) {
            return '';
        }

        $text = '';

        foreach ($content as $chunk) {
            if (is_array($chunk) && ($chunk['type'] ?? null) === 'text' && is_string($chunk['text'] ?? null)) {
                $text .= $chunk['text'];
            }
        }

        return trim($text);
    }
}
