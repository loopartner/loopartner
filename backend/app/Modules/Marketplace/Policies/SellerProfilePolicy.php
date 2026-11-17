<?php

namespace App\Modules\Marketplace\Policies;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;

/**
 * Identité professionnelle : seul un prestataire gère la sienne (/me/seller-profile). Elle
 * n'est jamais lue par un autre utilisateur, admins compris : elle n'apparaît que copiée dans
 * les devis qu'il envoie.
 */
class SellerProfilePolicy
{
    public function manage(User $user): bool
    {
        return $user->hasRole(AccountType::Prestataire->value);
    }
}
