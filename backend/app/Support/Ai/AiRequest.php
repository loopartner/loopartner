<?php

namespace App\Support\Ai;

/**
 * Requête à l'IA, construite par un module : consignes fixes (system), contenu de
 * l'utilisateur déjà minimisé (content, JSON) et schéma JSON imposé à la réponse.
 *
 * - feature : clé de la fonctionnalité (copilot.roadmap…), reprise dans le suivi des coûts ;
 * - promptVersion : version des consignes, gardée avec la réponse ;
 * - schema : JSON Schema de la réponse (objet), nommé schemaName.
 */
final readonly class AiRequest
{
    /**
     * @param  array<string, mixed>  $schema
     */
    public function __construct(
        public string $feature,
        public string $promptVersion,
        public string $system,
        public string $content,
        public string $schemaName,
        public array $schema,
        public int $maxOutputTokens,
    ) {}

    /**
     * Jetons d'entrée estimés par excès (un jeton pour trois octets, plus une marge), pour
     * réserver le coût avant l'appel.
     */
    public function estimatedInputTokens(): int
    {
        $bytes = strlen($this->system) + strlen($this->content) + strlen((string) json_encode($this->schema));

        return intdiv($bytes, 3) + 200;
    }
}
