<?php

namespace Database\Factories\Modules\Workspaces;

use App\Modules\Workspaces\Models\Goal;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Goal>
 */
class GoalFactory extends Factory
{
    protected $model = Goal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'title' => 'Ouvrir le site avant les fêtes',
            'description' => null,
            'target_date' => null,
            'position' => 0,
        ];
    }
}
