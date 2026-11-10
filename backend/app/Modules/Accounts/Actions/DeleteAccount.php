<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Supprime définitivement un compte.
 *
 * - les rôles sont détachés par le trait HasRoles de spatie ;
 * - le profil prestataire est supprimé par la clé étrangère (ON DELETE CASCADE) ;
 * - les sessions de l'utilisateur stockées en base sont supprimées, ce qui le déconnecte
 *   de tous ses appareils.
 */
class DeleteAccount
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $user->delete();
        });
    }
}
