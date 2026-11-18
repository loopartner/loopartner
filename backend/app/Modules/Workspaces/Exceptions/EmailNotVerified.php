<?php

namespace App\Modules\Workspaces\Exceptions;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Génération du copilote sans email vérifié (décision 61) : 403
 * {"message", "code": "email_not_verified"}. Le workspace reste utilisable sans vérification.
 */
class EmailNotVerified extends HttpException
{
    public const CODE = 'email_not_verified';

    public function __construct()
    {
        parent::__construct(403, 'Confirmez votre adresse email pour utiliser le copilote.');
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => self::CODE], 403);
    }
}
