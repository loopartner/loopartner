<?php

namespace App\Support\Ai\Drivers;

use App\Support\Ai\AiClient;
use App\Support\Ai\AiRequest;
use App\Support\Ai\AiResponse;
use Throwable;

/**
 * Pilote simulé (AI_DRIVER=fake) : tests et données de démonstration, **jamais en production**
 * (security:check-config). Aucun appel réseau.
 *
 * - Les réponses mises en file par respondWith() (tableau, ou exception à lever) sont rendues
 *   dans l'ordre ; sinon, une réponse d'exemple est construite à partir du schéma demandé.
 * - Chaque requête reçue est gardée (requests()) pour vérifier ce qui aurait été envoyé.
 */
final class FakeAiClient implements AiClient
{
    public const MODEL = 'fake-model';

    /** @var list<array<string, mixed>|Throwable> */
    private array $queue = [];

    /** @var list<AiRequest> */
    private array $requests = [];

    public function __construct(private int $inputTokens = 1200, private int $outputTokens = 800) {}

    public function name(): string
    {
        return 'fake';
    }

    public function providerLabel(): string
    {
        return (string) config('ai.providers.fake.label');
    }

    public function enabled(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>|Throwable  ...$responses
     */
    public function respondWith(array|Throwable ...$responses): self
    {
        array_push($this->queue, ...$responses);

        return $this;
    }

    /**
     * Jetons comptés pour les prochaines réponses.
     */
    public function usage(int $inputTokens, int $outputTokens): self
    {
        $this->inputTokens = $inputTokens;
        $this->outputTokens = $outputTokens;

        return $this;
    }

    /**
     * @return list<AiRequest>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function generate(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue) ?? self::example($request->schema);

        if ($next instanceof Throwable) {
            throw $next;
        }

        return new AiResponse(is_array($next) ? $next : [], $this->inputTokens, $this->outputTokens, self::MODEL);
    }

    /**
     * Valeur d'exemple conforme à un schéma JSON simple (objets, tableaux, chaînes, nombres,
     * booléens, listes fermées).
     *
     * @param  array<string, mixed>  $schema
     */
    public static function example(array $schema): mixed
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        $type = $schema['type'] ?? 'string';
        $type = is_array($type) ? (array_values(array_diff($type, ['null']))[0] ?? 'string') : $type;

        return match ($type) {
            'object' => collect((array) ($schema['properties'] ?? []))
                ->map(fn (mixed $property): mixed => self::example(is_array($property) ? $property : []))
                ->all(),
            'array' => [self::example(is_array($schema['items'] ?? null) ? $schema['items'] : [])],
            'integer', 'number' => 1,
            'boolean' => true,
            default => 'Exemple de texte généré',
        };
    }
}
