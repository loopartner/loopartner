<?php

namespace App\Modules\Marketplace\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Fonctionnalité Premium demandée sans Premium : 403 avec le code stable commun au Blog et à
 * Compliance ("premium_required") et la fonctionnalité concernée, documentés pour le frontend :
 *
 *   {"message": "…", "code": "premium_required", "feature": "quote_comparison"}
 *
 * Fonctionnalités : quote_comparison, quote_comparison_export.
 */
class PremiumFeatureRequired extends HttpException
{
    public const CODE = 'premium_required';

    /**
     * @var array<string, string>
     */
    private const MESSAGES = [
        'quote_comparison' => 'La comparaison des devis côte à côte, avec les éléments manquants et les questions à poser, est réservée au Premium. Chaque devis reçu reste lisible gratuitement.',
        'quote_comparison_export' => 'L\'export PDF de la comparaison des devis est réservé au Premium.',
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
