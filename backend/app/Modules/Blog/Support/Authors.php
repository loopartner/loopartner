<?php

namespace App\Modules\Blog\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Blog\Enums\AuthorKind;

/**
 * Qui peut écrire un article, et sous quelle présentation.
 *
 * - L'équipe (rédacteurs et admins) : articles signés « la rédaction », publiés sans
 *   validation (avec la 2FA de session), sans quota ;
 * - les prestataires avec un profil publié et au moins une catégorie (Action publique
 *   HasPublishedProviderProfile du module Accounts) ;
 * - les entrepreneurs.
 *
 * Les rôles sont lus avec l'enum public AccountType et les noms de rôle de l'équipe.
 */
final class Authors
{
    public const ADMIN = 'admin';

    public const REDACTEUR = 'redacteur';

    /** @var list<string> */
    public const TEAM_ROLES = [self::REDACTEUR, self::ADMIN];

    /** @var list<string> */
    public const ROLES = [AccountType::Prestataire->value, AccountType::Entrepreneur->value, self::REDACTEUR, self::ADMIN];

    public static function hasAuthorRole(User $user): bool
    {
        return $user->hasAnyRole(self::ROLES);
    }

    public static function isTeam(User $user): bool
    {
        return $user->hasAnyRole(self::TEAM_ROLES);
    }

    public static function isAdmin(User $user): bool
    {
        return $user->hasRole(self::ADMIN);
    }

    /**
     * Type d'auteur d'après le rôle (null si l'utilisateur ne peut pas écrire). Un membre de
     * l'équipe écrit toujours au nom de la rédaction.
     */
    public static function kindOf(User $user): ?AuthorKind
    {
        return match (true) {
            self::isTeam($user) => AuthorKind::Editorial,
            $user->hasRole(AccountType::Prestataire->value) => AuthorKind::Provider,
            $user->hasRole(AccountType::Entrepreneur->value) => AuthorKind::Entrepreneur,
            default => null,
        };
    }

    /**
     * L'utilisateur peut-il (encore) écrire des articles de ce type ? Un prestataire doit avoir
     * un profil publié avec au moins une catégorie.
     */
    public static function canWriteAs(?User $user, AuthorKind $kind): bool
    {
        if ($user === null || self::kindOf($user) !== $kind) {
            return false;
        }

        return $kind !== AuthorKind::Provider || app(HasPublishedProviderProfile::class)->handle($user);
    }

    /**
     * Type de compte affiché à la modération (premier rôle de la plateforme).
     */
    public static function accountType(User $user): ?string
    {
        $roles = [AccountType::Particulier->value, ...self::ROLES];

        return $user->getRoleNames()->first(fn (string $role): bool => in_array($role, $roles, true));
    }
}
