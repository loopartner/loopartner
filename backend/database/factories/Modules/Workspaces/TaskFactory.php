<?php

namespace Database\Factories\Modules\Workspaces;

use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Tâche de test, rattachée à une étape et à son workspace.
 *
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    protected $model = Task::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'step_id' => Step::factory(),
            'workspace_id' => fn (array $attributes): int => Step::query()->findOrFail($attributes['step_id'])->workspace_id,
            'title' => 'Comparer trois devis',
            'notes' => null,
            'due_date' => null,
            'source' => ItemSource::User,
            'position' => 0,
        ];
    }
}
