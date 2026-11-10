<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Support\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;

/**
 * Supprime définitivement un compte.
 *
 * - les médias de l'utilisateur (avatar, logo, envois non rattachés) sont supprimés par la
 *   brique média, fichiers compris : la clé étrangère media.user_id (restrict) l'impose, car
 *   une cascade en base laisserait les fichiers sur le disque. Les fichiers sont effacés
 *   après la validation de la transaction ;
 * - les rôles sont détachés par le trait HasRoles de spatie ;
 * - le profil prestataire est supprimé par la clé étrangère (ON DELETE CASCADE) ;
 * - les sessions de l'utilisateur stockées en base sont supprimées, ce qui le déconnecte
 *   de tous ses appareils.
 */
class DeleteAccount
{
    public function __construct(private MediaLibrary $library) {}

    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))
                    ->table((string) config('session.table', 'sessions'))
                    ->where('user_id', $user->getKey())
                    ->delete();
            }

            $this->library->deleteOwnedBy($user);

            $user->delete();
        });
    }
}
