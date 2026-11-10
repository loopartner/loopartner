<?php

namespace App\Support\Media;

use App\Models\User;

/**
 * Limites d'upload d'un usage : poids, dimensions et rôles autorisés.
 *
 * Les images plus grandes que maxWidth × maxHeight sont réduites (jamais agrandies).
 * Les rôles sont des chaînes : le noyau ne dépend pas des enums des modules.
 */
final readonly class PurposeLimits
{
    /**
     * @param  list<string>|null  $allowedRoles  null : tous les utilisateurs
     */
    public function __construct(
        public int $maxKilobytes,
        public int $minWidth,
        public int $minHeight,
        public int $maxWidth,
        public int $maxHeight,
        public ?array $allowedRoles = null,
    ) {}

    public function allows(User $user): bool
    {
        return $this->allowedRoles === null || $user->hasAnyRole($this->allowedRoles);
    }
}
