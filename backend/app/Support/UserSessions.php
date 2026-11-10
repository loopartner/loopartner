<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Sessions d'un utilisateur stockées en base (driver "database", celui de la production).
 *
 * Les supprimer déconnecte l'utilisateur des appareils concernés. Avec un autre driver, rien
 * n'est fait : les sessions restantes sont alors refusées par AuthenticateSession (Sanctum), qui
 * compare l'empreinte du mot de passe enregistrée en session.
 */
final class UserSessions
{
    public static function delete(User $user, ?string $exceptSessionId = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
