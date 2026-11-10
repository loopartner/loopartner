<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounts\Actions\RegisterAccount;
use App\Modules\Accounts\Http\Requests\RegisterRequest;
use Illuminate\Http\JsonResponse;

/**
 * POST /auth/register : remplace l'inscription de Fortify.
 *
 * Toujours la même réponse (202), que l'adresse soit libre ou déjà utilisée, et jamais de
 * connexion automatique : l'utilisateur vérifie son adresse, puis se connecte.
 */
class RegisterController extends Controller
{
    public const MESSAGE = 'Vérifiez votre boîte mail pour terminer votre inscription.';

    public function __invoke(RegisterRequest $request, RegisterAccount $registerAccount): JsonResponse
    {
        /** @var array{name: string, email: string, password: string, account_type: string, locale?: string, timezone?: string, country?: string|null} $data */
        $data = $request->validated();

        $registerAccount->handle($data);

        return new JsonResponse(['message' => self::MESSAGE], 202);
    }
}
