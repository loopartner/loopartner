<?php

namespace App\Modules\Marketplace\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Aucun taux de TVA vérifié n'est en vigueur dans le pays du prestataire (décision 49) : aucun
 * devis ne peut être rédigé ni envoyé tant que l'expert-comptable n'a pas vérifié les règles.
 *
 *   {"message": "…", "code": "vat_rates_unavailable"}
 */
class VatRatesUnavailable extends HttpException
{
    public const CODE = 'vat_rates_unavailable';

    public function __construct()
    {
        parent::__construct(409, 'Les devis ne sont pas encore disponibles : aucun taux de TVA vérifié n\'est en vigueur.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 409);
    }
}
