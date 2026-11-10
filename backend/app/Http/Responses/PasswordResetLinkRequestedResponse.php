<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Réponse unique de POST /auth/forgot-password, que le lien ait été envoyé ou non (adresse
 * inconnue, demande trop rapprochée…) : elle ne révèle jamais si un compte existe.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.';

    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(['message' => self::MESSAGE]);
    }
}
