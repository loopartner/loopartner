<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Models\ProviderProfile;

/**
 * Action publique du module Accounts : l'utilisateur a-t-il un profil prestataire publié avec
 * au moins une catégorie de services ?
 *
 * Utilisée par d'autres modules (Directory : consultation des projets) sans exposer le modèle
 * ProviderProfile, privé au module.
 */
class HasPublishedProviderProfile
{
    public function handle(User $user): bool
    {
        return ProviderProfile::query()
            ->published()
            ->where('user_id', $user->id)
            ->whereHas('categories')
            ->exists();
    }
}
