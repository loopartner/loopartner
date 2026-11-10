<?php

namespace App\Modules\Accounts\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Accounts\Models\ProviderProfile;

/**
 * Seuls les prestataires gèrent un profil prestataire, et uniquement le leur.
 * La page publique (GET /providers/{slug}) ne passe pas par cette Policy.
 */
class ProviderProfilePolicy
{
    /**
     * Consulter son propre profil (GET /me/provider-profile).
     */
    public function viewOwn(User $user): bool
    {
        return $user->hasRole(AccountType::Prestataire->value);
    }

    /**
     * Créer ou mettre à jour son propre profil (PUT /me/provider-profile).
     */
    public function upsert(User $user): bool
    {
        return $user->hasRole(AccountType::Prestataire->value);
    }

    /**
     * Modifier un profil existant : il doit appartenir à l'utilisateur.
     */
    public function update(User $user, ProviderProfile $profile): bool
    {
        return $this->upsert($user) && $profile->user_id === $user->id;
    }
}
