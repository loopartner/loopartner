<?php

namespace App\Listeners;

use App\Models\User;
use App\Support\SecurityLog;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationEnabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Écrit dans le journal de sécurité les événements d'authentification de Laravel et de Fortify.
 *
 * Seuls l'identifiant de l'utilisateur et, pour les échecs de connexion, l'empreinte de l'email
 * sont journalisés : jamais les identifiants reçus (l'événement Failed contient le mot de passe).
 *
 * Listener découvert automatiquement (dossier app/Listeners). Les autres événements de sécurité
 * sont écrits là où ils se produisent : limites de débit (bootstrap/app.php), refus
 * d'autorisation (AppServiceProvider), demande de réinitialisation
 * (PasswordResetLinkRequestedResponse), inscription sur une adresse existante (RegisterAccount),
 * lien de vérification refusé (EmailVerificationController), suppression de compte (DeleteAccount).
 */
class LogSecurityEvents
{
    public function handleLogin(Login $event): void
    {
        SecurityLog::record('auth.login', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? null;

        SecurityLog::record('auth.login_failed', [
            'user_id' => $event->user?->getAuthIdentifier(),
            'email_hash' => is_string($email) ? SecurityLog::emailHash($email) : null,
        ], 'warning');
    }

    public function handleLogout(Logout $event): void
    {
        SecurityLog::record('auth.logout', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleRegistered(Registered $event): void
    {
        SecurityLog::record('auth.registered', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleVerified(Verified $event): void
    {
        // L'événement ne type l'utilisateur que comme MustVerifyEmail.
        SecurityLog::record('auth.email_verified', [
            'user_id' => $event->user instanceof User ? $event->user->getKey() : null,
        ]);
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        SecurityLog::record('auth.password_reset', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handlePasswordUpdated(PasswordUpdatedViaController $event): void
    {
        SecurityLog::record('auth.password_changed', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleTwoFactorEnabled(TwoFactorAuthenticationEnabled $event): void
    {
        SecurityLog::record('auth.two_factor_enabled', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        SecurityLog::record('auth.two_factor_confirmed', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        SecurityLog::record('auth.two_factor_disabled', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        SecurityLog::record('auth.two_factor_recovery_codes_regenerated', ['user_id' => $event->user->getAuthIdentifier()]);
    }

    /**
     * Défi 2FA échoué : l'utilisateur n'est pas encore connecté, son identifiant vient de l'événement.
     */
    public function handleTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        SecurityLog::record('auth.two_factor_failed', ['user_id' => $event->user->getAuthIdentifier()], 'warning');
    }
}
