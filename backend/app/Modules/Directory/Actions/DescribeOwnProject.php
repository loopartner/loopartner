<?php

namespace App\Modules\Directory\Actions;

use App\Models\User;
use App\Modules\Directory\Models\Project;

/**
 * Action publique du module Directory : résumé d'un projet de l'utilisateur, désigné par son
 * identifiant interne (gardé par un autre module, jamais exposé), en données simples (module
 * Workspaces : projet relié à un workspace). Null si le projet n'existe plus ou n'est pas le
 * sien.
 *
 * @phpstan-type OwnProjectSummary array{uuid: string, title: string, status: string}
 */
class DescribeOwnProject
{
    /**
     * @return OwnProjectSummary|null
     */
    public function handle(int $projectId, User $user): ?array
    {
        $project = Project::query()->whereKey($projectId)->where('user_id', $user->id)->first();

        return $project === null ? null : [
            'uuid' => $project->uuid,
            'title' => $project->title,
            'status' => $project->status->value,
        ];
    }
}
