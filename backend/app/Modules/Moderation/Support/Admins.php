<?php

namespace App\Modules\Moderation\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Admins qui reçoivent les alertes et le récapitulatif de la modération : rôle admin, email
 * vérifié et double authentification confirmée (seuls eux peuvent ouvrir la modération).
 */
final class Admins
{
    public const ROLE = 'admin';

    /**
     * @return Collection<int, User>
     */
    public static function recipients(): Collection
    {
        return User::role(self::ROLE)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_confirmed_at')
            ->get();
    }
}
