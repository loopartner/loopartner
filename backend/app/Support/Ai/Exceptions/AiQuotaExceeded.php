<?php

namespace App\Support\Ai\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Quota ou plafond de coût de l'utilisateur atteint : 429
 * {"message", "code": "ai_quota_exceeded", "scope", "resets_at"}.
 *
 * scope : daily, monthly (quotas de génération du Premium) ou budget (plafond de coût mensuel
 * de l'utilisateur).
 */
class AiQuotaExceeded extends HttpException
{
    public const CODE = 'ai_quota_exceeded';

    public function __construct(public readonly string $scope, public readonly CarbonInterface $resetsAt)
    {
        parent::__construct(429, match ($scope) {
            'daily' => 'Vous avez atteint le nombre de générations du jour.',
            'monthly' => 'Vous avez atteint le nombre de générations du mois.',
            default => 'Vous avez atteint la limite d\'utilisation de l\'IA pour ce mois.',
        });
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'scope' => $this->scope,
            'resets_at' => $this->resetsAt->toIso8601ZuluString(),
        ], 429);
    }
}
