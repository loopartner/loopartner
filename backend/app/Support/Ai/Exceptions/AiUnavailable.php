<?php

namespace App\Support\Ai\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * IA indisponible : 503 {"message", "code": "ai_unavailable", "reason"}.
 *
 * Raisons : disabled (IA désactivée en configuration), execution_time (max_execution_time de
 * PHP trop court), pricing (modèle sans prix), capacity (plafond global atteint), provider
 * (fournisseur injoignable ou en erreur).
 */
class AiUnavailable extends HttpException
{
    public const CODE = 'ai_unavailable';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        'disabled' => 'Le copilote est indisponible pour le moment.',
        'execution_time' => 'Le copilote est indisponible pour le moment.',
        'pricing' => 'Le copilote est indisponible pour le moment.',
        'capacity' => 'Le copilote a atteint sa capacité pour le moment. Réessayez plus tard.',
        'provider' => 'Le service d\'IA ne répond pas. Réessayez dans quelques minutes : cette tentative n\'est pas décomptée.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(503, self::MESSAGES[$reason] ?? self::MESSAGES['disabled']);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'reason' => $this->reason,
        ], 503);
    }
}
