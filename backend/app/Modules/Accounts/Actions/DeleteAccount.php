<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Events\AccountDeleting;
use App\Support\Media\MediaLibrary;
use App\Support\SecurityLog;
use App\Support\UserSessions;
use Illuminate\Support\Facades\DB;

/**
 * Supprime définitivement un compte.
 *
 * - l'événement AccountDeleting est envoyé en premier, dans la transaction : les autres modules
 *   traitent les données de l'utilisateur (Directory : demandes supprimées ou anonymisées,
 *   conversations conservées pour l'autre participant puis purgées) ;
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
        SecurityLog::record('account.deleted', ['user_id' => $user->id]);

        DB::transaction(function () use ($user): void {
            AccountDeleting::dispatch($user);

            UserSessions::delete($user);

            $this->library->deleteOwnedBy($user);

            $user->delete();
        });
    }
}
