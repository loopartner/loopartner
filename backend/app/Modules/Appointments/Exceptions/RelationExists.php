<?php

namespace App\Modules\Appointments\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Réservation sur la page publique alors qu'une mise en relation acceptée existe déjà avec ce
 * prestataire : le rendez-vous se prend depuis cette relation (décision 52). 409 avec un code
 * stable et l'uuid de la demande, pour que le frontend y redirige :
 *
 *   {"message": "…", "code": "relation_exists", "match_request": "<uuid>"}
 */
class RelationExists extends HttpException
{
    public const CODE = 'relation_exists';

    public function __construct(public readonly string $matchRequestUuid)
    {
        parent::__construct(409, 'Vous êtes déjà en relation avec ce prestataire : prenez rendez-vous depuis votre conversation.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
            'match_request' => $this->matchRequestUuid,
        ], 409);
    }
}
