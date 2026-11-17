<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Directory\Models\Project;

/**
 * Action publique du module Directory : un projet de l'utilisateur, désigné par son uuid, en
 * données simples (module Marketplace : comparateur de devis). Null si le projet n'existe pas,
 * n'est pas le sien, ou si son rôle ne gère pas de projets : l'appelant répond 404.
 *
 * @phpstan-type OwnProject array{id: int, uuid: string, title: string, remote_ok: bool}
 */
class FindOwnProject
{
    /**
     * @return OwnProject|null
     */
    public function handle(string $uuid, User $user): ?array
    {
        if (! $user->hasAnyRole([AccountType::Particulier->value, AccountType::Entrepreneur->value])) {
            return null;
        }

        $project = Project::query()->where('uuid', $uuid)->where('user_id', $user->id)->first();

        return $project === null ? null : [
            'id' => $project->id,
            'uuid' => $project->uuid,
            'title' => $project->title,
            'remote_ok' => $project->remote_ok,
        ];
    }
}
