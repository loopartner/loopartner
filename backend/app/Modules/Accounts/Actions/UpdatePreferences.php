<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;

/**
 * Met à jour le nom et les préférences d'un utilisateur. Jamais son email ni ses rôles.
 */
class UpdatePreferences
{
    /**
     * @param  array{name?: string, locale?: string, timezone?: string, country?: string|null}  $data
     */
    public function handle(User $user, array $data): User
    {
        $user->fill(array_intersect_key($data, array_flip(['name', 'locale', 'timezone', 'country'])));
        $user->save();

        return $user;
    }
}
