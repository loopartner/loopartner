<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;

/**
 * Rôles autorisés à organiser des événements : prestataires (avec un profil publié, vérifié
 * par EventPolicy), entrepreneurs, rédacteurs et admins. Seuls les événements d'un admin sont
 * publiés sans validation.
 */
final class Organizers
{
    public const ADMIN = 'admin';

    public const REDACTEUR = 'redacteur';

    /** @var list<string> */
    public const ROLES = [AccountType::Prestataire->value, AccountType::Entrepreneur->value, self::REDACTEUR, self::ADMIN];

    public static function hasOrganizerRole(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    /**
     * Prestataire : présenté par son profil public, sans nom d'organisateur.
     */
    public static function isProvider(User $user): bool
    {
        return $user->hasRole(AccountType::Prestataire->value);
    }

    public static function isAdmin(User $user): bool
    {
        return $user->hasRole(self::ADMIN);
    }

    /**
     * Type de compte affiché (premier rôle de la plateforme), sans autre donnée personnelle.
     */
    public static function accountType(User $user): ?string
    {
        $roles = [AccountType::Particulier->value, ...self::ROLES];

        return $user->getRoleNames()->first(fn (string $role): bool => in_array($role, $roles, true));
    }
}
