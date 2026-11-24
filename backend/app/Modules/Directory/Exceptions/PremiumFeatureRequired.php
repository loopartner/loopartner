<?php

namespace App\Modules\Directory\Exceptions;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fonctionnalité Premium demandée sans Premium : 403 avec le code stable commun aux autres
 * modules ("premium_required") et la fonctionnalité concernée, documentés pour le frontend :
 *
 *   {"message": "…", "code": "premium_required", "feature": "project_access"}
 *
 * Fonctionnalité : project_access (détail des projets et propositions, décision 81).
 */
class PremiumFeatureRequired extends HttpException
{
    public const CODE = 'premium_required';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        'project_access' => 'Le détail des projets et les propositions sont réservés aux prestataires Premium. Les projets restent visibles dans la vitrine publique.',
    ];

    public function __construct(public readonly string $feature)
    {
        parent::__construct(403, self::MESSAGES[$feature] ?? 'Cette fonctionnalité est réservée au Premium.');
    }

    /**
     * Transforme un refus de la Policy marqué du code premium_required en cette exception, pour
     * que le frontend reçoive le code stable plutôt qu'un simple message.
     */
    public static function throwIfDenied(Response $response): void
    {
        if ($response->denied() && $response->code() === self::CODE) {
            throw new self('project_access');
        }
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
