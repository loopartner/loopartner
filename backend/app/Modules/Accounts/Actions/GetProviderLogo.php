<?php

namespace App\Modules\Accounts\Actions;

use App\Models\Media;
use App\Modules\Accounts\Models\ProviderProfile;

/**
 * Action publique du module Accounts : logo actuel du profil prestataire d'un utilisateur
 * (média du noyau), ou null. Utilisée par le module Marketplace pour les devis PDF
 * personnalisés (Premium), sans exposer le modèle ProviderProfile.
 */
class GetProviderLogo
{
    public function handle(int $userId): ?Media
    {
        return ProviderProfile::query()->with('logo')->where('user_id', $userId)->first()?->logo;
    }
}
