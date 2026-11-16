<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\SecurityLog;
use App\Support\TeamTwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Routes Fortify (fortify.middleware) : un membre de l'équipe (admin, rédacteur) ne peut pas
 * désactiver sa double authentification, obligatoire pour lui (403 two_factor_required,
 * journalisé). Les autres comptes la désactivent librement.
 */
class ProtectTeamTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->route()?->getName() === 'two-factor.disable' && $user instanceof User && TeamTwoFactor::isTeamMember($user)) {
            SecurityLog::record('auth.two_factor_disable_denied', [], 'warning', $request);

            return response()->json([
                'message' => 'La double authentification est obligatoire pour l\'équipe : elle ne peut pas être désactivée.',
                'code' => TeamTwoFactor::CODE,
            ], 403);
        }

        return $next($request);
    }
}
