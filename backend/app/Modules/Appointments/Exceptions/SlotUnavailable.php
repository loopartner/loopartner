<?php

namespace App\Modules\Appointments\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Créneau qui n'est pas (ou plus) libre : déjà retenu, fermé, hors délai ou hors horizon.
 * 409 avec un code stable pour le frontend, qui recharge alors les créneaux :
 *
 *   {"message": "…", "code": "slot_unavailable"}
 */
class SlotUnavailable extends HttpException
{
    public const CODE = 'slot_unavailable';

    public function __construct()
    {
        parent::__construct(409, 'Ce créneau n\'est plus disponible : choisissez-en un autre.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 409);
    }
}
