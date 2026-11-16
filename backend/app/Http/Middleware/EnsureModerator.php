<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityLog;
use App\Support\TeamTwoFactor;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints de modération (/admin/…) : rôle de modérateur ET double authentification.
 *
 * Rôles acceptés : "admin" par défaut ; une route peut en indiquer d'autres en paramètre
 * (EnsureModerator::class.':admin,redacteur' pour la modération éditoriale du Blog).
 *
 * - Pas l'un de ces rôles : 404, comme une route inconnue. La surface d'administration n'est pas révélée.
 * - Modérateur sans 2FA activée et confirmée : 403, avec un message qui demande de l'activer.
 * - Modérateur dont la session n'a pas été ouverte avec un code 2FA (session antérieure à
 *   l'activation, par exemple) : 403, avec un message qui demande de se reconnecter.
 *
 * Les deux 403 portent "code": "two_factor_required" et "reason" ("not_enabled" ou
 * "session") : le frontend envoie vers l'activation ou la reconnexion (TeamTwoFactor).
 *
 * Chaque refus est journalisé (moderation.access_denied, avec reason). À placer après
 * auth:sanctum (l'anonyme reçoit 401) et verified.
 */
class EnsureModerator
{
    public const ADMIN_ROLE = 'admin';

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $roles = $roles === [] ? [self::ADMIN_ROLE] : $roles;

        if (! $user instanceof User || ! $user->hasAnyRole($roles)) {
            $this->deny($request, 'role');
            abort(404);
        }

        $missing = TeamTwoFactor::missing($user, $request);

        if ($missing === 'not_enabled') {
            $this->deny($request, 'two_factor_disabled');
            $this->refuse('La modération exige la double authentification : activez-la et confirmez-la dans votre compte, puis reconnectez-vous.', $missing);
        }

        if ($missing === 'session') {
            $this->deny($request, 'two_factor_session');
            $this->refuse('Reconnectez-vous en saisissant votre code de double authentification pour accéder à la modération.', $missing);
        }

        return $next($request);
    }

    private function refuse(string $message, string $reason): never
    {
        throw new HttpResponseException(response()->json([
            'message' => $message,
            'code' => TeamTwoFactor::CODE,
            'reason' => $reason,
        ], 403));
    }

    private function deny(Request $request, string $reason): void
    {
        SecurityLog::record('moderation.access_denied', ['reason' => $reason], 'warning', $request);
    }
}
