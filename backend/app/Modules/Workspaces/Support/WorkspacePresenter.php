<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use App\Modules\Workspaces\Http\Resources\WorkspaceResource;
use App\Modules\Workspaces\Models\Workspace;

/**
 * Prépare la réponse d'un workspace : contenu chargé (objectifs, étapes, tâches, catégories),
 * état verrouillé, projet Directory relié, et quota de l'utilisateur pour les listes.
 */
final class WorkspacePresenter
{
    public function __construct(private Workspaces $workspaces, private Plan $plan, private LinkedProjects $linkedProjects) {}

    public function resource(Workspace $workspace, bool $detailed = true): WorkspaceResource
    {
        if ($detailed) {
            $workspace->load(['goals', 'steps.category', 'steps.tasks']);
        }

        return new WorkspaceResource(
            $workspace,
            $this->workspaces->isLocked($workspace),
            $this->linkedProjects->describe($workspace),
        );
    }

    /**
     * @return array{active: int, active_limit: int|null, total: int, total_limit: int, templates: bool}
     */
    public function quota(User $user): array
    {
        return [
            'active' => Workspace::query()->where('user_id', $user->id)->where('status', WorkspaceStatus::Active->value)->count(),
            'active_limit' => $this->plan->limit($user, 'workspaces.active'),
            'total' => Workspace::query()->where('user_id', $user->id)->count(),
            'total_limit' => (int) config('workspaces.max_workspaces'),
            'templates' => $this->plan->allows($user, 'workspaces.templates'),
        ];
    }
}
