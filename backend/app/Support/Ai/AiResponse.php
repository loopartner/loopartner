<?php

namespace App\Support\Ai;

/**
 * Réponse de l'IA : objet JSON décodé (donnée NON FIABLE, à nettoyer par le module), jetons
 * consommés et modèle. Jamais journalisée.
 */
final readonly class AiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public array $data,
        public int $inputTokens,
        public int $outputTokens,
        public string $model,
    ) {}
}
