<?php

namespace App\Listeners;

use App\Support\TwoFactorSession;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;

/**
 * Marque la session ouverte par le défi 2FA de Fortify (voir App\Support\TwoFactorSession), et
 * retire la marque quand la 2FA est désactivée. Listener découvert automatiquement.
 */
class TrackTwoFactorSession
{
    public function handleValidCode(ValidTwoFactorAuthenticationCodeProvided $event): void
    {
        if (app()->bound('session')) {
            session()->put(TwoFactorSession::KEY, now()->getTimestamp());
        }
    }

    public function handleDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        if (app()->bound('session')) {
            session()->forget(TwoFactorSession::KEY);
        }
    }
}
