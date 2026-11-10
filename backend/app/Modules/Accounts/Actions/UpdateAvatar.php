<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rattache, remplace ou retire l'avatar d'un utilisateur.
 *
 * L'avatar précédent est supprimé (ligne et fichier) par la brique média.
 */
class UpdateAvatar
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  string|null  $uuid  uuid d'un média "avatar" de l'utilisateur, ou null pour retirer l'avatar
     *
     * @throws ValidationException si le média n'est pas rattachable
     */
    public function handle(User $user, ?string $uuid): User
    {
        DB::transaction(function () use ($user, $uuid): void {
            $mediaId = $this->library->replace($user, MediaPurpose::Avatar, $uuid, $user->avatar_media_id, 'avatar');

            $user->forceFill(['avatar_media_id' => $mediaId])->save();
        });

        return $user->load('avatar');
    }
}
