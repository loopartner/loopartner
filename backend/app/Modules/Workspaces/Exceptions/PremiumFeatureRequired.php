<?php

namespace App\Modules\Workspaces\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fonctionnalité ou quota Premium demandé sans Premium : 403 avec le code stable commun aux
 * autres modules ("premium_required") et la fonctionnalité concernée :
 *
 *   {"message": "…", "code": "premium_required", "feature": "multiple_workspaces"}
 *
 * Fonctionnalités : multiple_workspaces, workspace_templates, copilot_roadmaps,
 * copilot_specifications.
 */
class PremiumFeatureRequired extends HttpException
{
    public const CODE = 'premium_required';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        'multiple_workspaces' => 'L\'offre gratuite comprend un workspace actif. Archivez-le, ou passez au Premium pour en gérer plusieurs.',
        'workspace_templates' => 'Les modèles de projet sont réservés au Premium.',
        'copilot_roadmaps' => 'Vous avez utilisé les feuilles de route du copilote comprises dans l\'offre gratuite. Le Premium en permet davantage.',
        'copilot_specifications' => 'La rédaction d\'un cahier des charges par le copilote est réservée au Premium.',
    ];

    public function __construct(public readonly string $feature)
    {
        parent::__construct(403, self::MESSAGES[$feature] ?? 'Cette fonctionnalité est réservée au Premium.');
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
