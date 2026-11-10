<?php

namespace App\Modules\Accounts\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;

/**
 * Vérification de l'adresse email (remplace celle de Fortify, qui exige d'être connecté).
 *
 * Le lien envoyé par email est signé (APP_KEY), expire après 60 minutes et contient l'id de
 * l'utilisateur et le sha1 de son adresse. Il fonctionne sans session, puis redirige toujours
 * vers la page de connexion du frontend (FRONTEND_URL/login), jamais vers une URL reçue.
 */
class EmailVerificationController extends Controller
{
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        if (! URL::hasCorrectSignature($request)) {
            return $this->redirectToLogin('verification=invalid');
        }

        if (! URL::signatureHasNotExpired($request)) {
            return $this->redirectToLogin('verification=expired');
        }

        $user = User::query()->find((int) $id);

        if ($user === null || ! hash_equals(hash('sha1', $user->getEmailForVerification()), $hash)) {
            return $this->redirectToLogin('verification=invalid');
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return $this->redirectToLogin('verified=1');
    }

    /**
     * Renvoie le lien à l'utilisateur connecté (POST /auth/email/verification-notification).
     */
    public function resend(Request $request): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->noContent();
        }

        $user->sendEmailVerificationNotification();

        return new JsonResponse(['message' => 'Un nouveau lien de vérification vous a été envoyé.'], 202);
    }

    private function redirectToLogin(string $query): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/login?'.$query);
    }
}
