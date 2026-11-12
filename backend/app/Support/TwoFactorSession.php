<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Double authentification au niveau de la session.
 *
 * Il ne suffit pas que la 2FA soit activée sur le compte : une session ouverte avant son
 * activation (ou volée à ce moment-là) n'a jamais présenté de code. Les fonctions sensibles
 * (modération) exigent donc une session ouverte par le défi 2FA de Fortify, marquée par
 * App\Listeners\TrackTwoFactorSession (événement ValidTwoFactorAuthenticationCodeProvided, code
 * TOTP ou code de secours). La marque survit à la régénération de l'identifiant de session qui
 * suit la connexion, et disparaît à la déconnexion ou à la désactivation de la 2FA.
 */
final class TwoFactorSession
{
    public const KEY = 'auth.two_factor_passed_at';

    /**
     * La 2FA est-elle activée et confirmée sur le compte ?
     */
    public static function isEnabledFor(User $user): bool
    {
        return $user->two_factor_secret !== null && $user->two_factor_confirmed_at !== null;
    }

    /**
     * La session de la requête a-t-elle été ouverte avec un code 2FA, pour ce compte où la 2FA
     * est toujours active ?
     */
    public static function passed(Request $request): bool
    {
        $user = $request->user();

        return $user instanceof User
            && self::isEnabledFor($user)
            && $request->hasSession()
            && is_int($request->session()->get(self::KEY));
    }
}
