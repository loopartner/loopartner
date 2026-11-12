<?php

namespace App\Modules\Accounts\Actions;

use App\Modules\Accounts\Models\PremiumGrant;

/**
 * Action publique du module Accounts : l'utilisateur a-t-il le statut premium en ce moment ?
 *
 * Utilisée par d'autres modules (Blog : quota d'articles) sans exposer le modèle PremiumGrant.
 * Le statut est calculé à la lecture : une période terminée ne compte plus, sans tâche
 * planifiée.
 */
class IsPremium
{
    public function handle(int $userId): bool
    {
        return PremiumGrant::query()->active()->where('user_id', $userId)->exists();
    }
}
