<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Workspace vu par son propriétaire (seul à le voir). Résumé dans les listes ; en détail
 * (relations chargées) : objectifs, étapes avec leurs tâches, avancement. Jamais d'identifiant
 * interne ; le projet Directory relié n'est décrit que par son uuid, son titre et son statut.
 *
 * - locked : workspace en trop après la fin du Premium (lecture, archivage, suppression).
 *
 * @mixin Workspace
 */
class WorkspaceResource extends JsonResource
{
    /**
     * @param  array{uuid: string, title: string, status: string}|null  $linkedProject
     */
    public function __construct(Workspace $resource, private bool $locked, private ?array $linkedProject = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->resource;
        $detailed = $workspace->relationLoaded('steps');
        $tasks = $detailed ? $workspace->steps->flatMap(fn (Step $step) => $step->tasks) : null;

        return [
            'uuid' => $workspace->uuid,
            'title' => $workspace->title,
            'description' => $workspace->description,
            'country' => $workspace->country,
            'budget' => $workspace->budget_min === null && $workspace->budget_max === null ? null : [
                'min' => $workspace->budget_min,
                'max' => $workspace->budget_max,
                'currency' => $workspace->currency,
            ],
            'remote_ok' => $workspace->remote_ok,
            'desired_start_date' => $workspace->desired_start_date?->toDateString(),
            'status' => $workspace->status->value,
            'locked' => $this->locked,
            'template' => $workspace->template_code,
            'linked_project' => $this->linkedProject,
            'progress' => $tasks === null ? null : [
                'steps_total' => $workspace->steps->count(),
                'steps_done' => $workspace->steps->filter(fn (Step $step): bool => $step->completed_at !== null)->count(),
                'tasks_total' => $tasks->count(),
                'tasks_done' => $tasks->filter(fn ($task): bool => $task->completed_at !== null)->count(),
                'percent' => $tasks->count() === 0 ? 0 : intdiv(100 * $tasks->filter(fn ($task): bool => $task->completed_at !== null)->count(), $tasks->count()),
            ],
            'goals' => $workspace->relationLoaded('goals')
                ? $workspace->goals->map(fn (Goal $goal): array => (new GoalResource($goal))->resolve($request))->values()->all()
                : null,
            'steps' => $detailed
                ? $workspace->steps->map(fn (Step $step): array => (new StepResource($step, $workspace->country))->resolve($request))->values()->all()
                : null,
            'activated_at' => $workspace->activated_at->toIso8601ZuluString(),
            'archived_at' => $workspace->archived_at?->toIso8601ZuluString(),
            'created_at' => $workspace->created_at?->toIso8601ZuluString(),
            'updated_at' => $workspace->updated_at?->toIso8601ZuluString(),
        ];
    }
}
