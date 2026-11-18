<?php

namespace App\Support\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Réponse de l'IA inexploitable (JSON invalide ou tronqué, vide après nettoyage) : 502
 * {"message", "code": "ai_invalid_response"}. La raison interne décrit le défaut sans jamais
 * reprendre le contenu ; la génération n'est pas décomptée du quota.
 */
class AiInvalidResponse extends RuntimeException
{
    public const CODE = 'ai_invalid_response';

    /**
     * Les jetons d'une réponse reçue mais inexploitable (tronquée…) restent facturés par le
     * fournisseur : ils sont comptés dans les plafonds de coût, pas dans le quota.
     */
    public function __construct(public readonly string $reason, public readonly int $inputTokens = 0, public readonly int $outputTokens = 0)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'La réponse du service d\'IA est inexploitable. Réessayez : cette tentative n\'est pas décomptée.',
            'code' => self::CODE,
        ], 502);
    }
}
