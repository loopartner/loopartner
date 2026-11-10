<?php

namespace App\Http\Responses;

use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

/**
 * Réponse unique de POST /auth/forgot-password, que le lien ait été envoyé ou non (adresse
 * inconnue, demande trop rapprochée…) : elle ne révèle jamais si un compte existe.
 *
 * L'issue réelle n'est écrite que dans le journal de sécurité, avec l'empreinte de l'email.
 */
class PasswordResetLinkRequestedResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public const MESSAGE = 'Si un compte correspond à cette adresse, un lien de réinitialisation vient d\'être envoyé.';

    /**
     * @param  string  $status  statut du PasswordBroker, transmis par Fortify
     */
    public function __construct(private string $status = '') {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): JsonResponse
    {
        SecurityLog::record('auth.password_reset_requested', [
            'email_hash' => SecurityLog::emailHash($request->string('email')->toString()),
            'outcome' => match ($this->status) {
                Password::RESET_LINK_SENT => 'sent',
                Password::INVALID_USER => 'unknown_email',
                Password::RESET_THROTTLED => 'throttled',
                default => 'failed',
            },
        ], request: $request);

        return new JsonResponse(['message' => self::MESSAGE]);
    }
}
