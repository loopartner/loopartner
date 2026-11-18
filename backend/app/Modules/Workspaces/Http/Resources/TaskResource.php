<?php

namespace App\Modules\Workspaces\Http\Resources;

use App\Modules\Workspaces\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Tâche vue par le propriétaire du workspace. Jamais d'identifiant interne.
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Task $task */
        $task = $this->resource;

        return [
            'uuid' => $task->uuid,
            'title' => $task->title,
            'notes' => $task->notes,
            'due_date' => $task->due_date?->toDateString(),
            'completed' => $task->completed_at !== null,
            'completed_at' => $task->completed_at?->toIso8601ZuluString(),
            'source' => $task->source->value,
            'ai_generated' => $task->ai_generated_at !== null,
            'position' => $task->position,
        ];
    }
}
