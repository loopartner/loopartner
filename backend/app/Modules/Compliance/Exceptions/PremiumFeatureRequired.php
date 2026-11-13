<?php

namespace App\Modules\Compliance\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fonctionnalité premium demandée par un compte sans premium : 403 avec le même code stable que
 * le Blog ("premium_required"), et la fonctionnalité concernée dans une liste stable, documentée
 * pour le frontend :
 *
 *   {"message": "…", "code": "premium_required", "feature": "estimate"}
 *
 * Fonctionnalités : multiple_companies, estimate, custom_reminders, custom_threshold_levels,
 * multiple_journeys.
 */
class PremiumFeatureRequired extends HttpException
{
    public const CODE = 'premium_required';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        'multiple_companies' => 'Le suivi de plusieurs entreprises est réservé au premium. Sans premium, seule votre entreprise active reste modifiable : vous pouvez choisir laquelle.',
        'estimate' => 'L\'estimation des cotisations et impôts à mettre de côté est réservée au premium.',
        'custom_reminders' => 'Plusieurs délais de rappel, ou un délai personnalisé, sont réservés au premium.',
        'custom_threshold_levels' => 'Les paliers d\'alerte personnalisés sont réservés au premium.',
        'multiple_journeys' => 'Suivre plusieurs parcours en même temps est réservé au premium.',
    ];

    public function __construct(public readonly string $feature)
    {
        parent::__construct(403, self::MESSAGES[$feature] ?? 'Cette fonctionnalité est réservée au premium.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'feature' => $this->feature,
        ], 403);
    }
}
