<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Http\Resources\ServiceCategoryResource;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Étape vue par le propriétaire : tâches (si chargées), avancement, catégorie utile et
 * paramètres prêts pour la recherche de prestataires (GET /providers?category=…&country=…).
 *
 * @mixin Step
 */
class StepResource extends JsonResource
{
    public function __construct(Step $resource, private ?string $country = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Step $step */
        $step = $this->resource;
        $category = $step->category;
        $tasks = $step->relationLoaded('tasks') ? $step->tasks : null;

        return [
            'uuid' => $step->uuid,
            'title' => $step->title,
            'description' => $step->description,
            'due_date' => $step->due_date?->toDateString(),
            'completed' => $step->completed_at !== null,
            'completed_at' => $step->completed_at?->toIso8601ZuluString(),
            'category' => $category === null ? null : (new ServiceCategoryResource($category))->resolve($request),
            'providers_search' => $category === null ? null : array_filter(['category' => $category->slug, 'country' => $this->country]),
            'source' => $step->source->value,
            'ai_generated' => $step->ai_generated_at !== null,
            'position' => $step->position,
            'progress' => $tasks === null ? null : [
                'tasks_total' => $tasks->count(),
                'tasks_done' => $tasks->filter(fn (Task $task): bool => $task->completed_at !== null)->count(),
            ],
            'tasks' => $tasks === null ? null : $tasks->map(fn (Task $task): array => (new TaskResource($task))->resolve($request))->values()->all(),
        ];
    }
}
