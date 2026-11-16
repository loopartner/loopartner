<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Double authentification obligatoire pour l'équipe (rôles admin et redacteur, décision 17).
 *
 * - Toute fonction d'équipe (/admin/…, publication directe) exige une 2FA confirmée sur le
 *   compte ET une session ouverte avec un code (EnsureModerator, Events, Blog).
 * - Un membre de l'équipe ne peut pas désactiver sa 2FA (ProtectTeamTwoFactor).
 * - GET /me annonce two_factor_required pour que le frontend impose l'activation.
 */
final class TeamTwoFactor
{
    /** @var list<string> */
    public const ROLES = ['admin', 'redacteur'];

    public const CODE = 'two_factor_required';

    public static function isTeamMember(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Raison du refus d'une fonction d'équipe, ou null si la 2FA est en place pour cette
     * session : "not_enabled" (2FA non confirmée sur le compte) ou "session" (session ouverte
     * sans code).
     */
    public static function missing(User $user, Request $request): ?string
    {
        if (! TwoFactorSession::isEnabledFor($user)) {
            return 'not_enabled';
        }

        return TwoFactorSession::passed($request) ? null : 'session';
    }
}
