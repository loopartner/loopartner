<?php

namespace Database\Factories\Modules\Workspaces;

use App\Modules\Workspaces\Enums\ItemSource;
use App\Modules\Workspaces\Models\Step;
use App\Modules\Workspaces\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Step>
 */
class StepFactory extends Factory
{
    protected $model = Step::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'workspace_id' => Workspace::factory(),
            'title' => 'Choisir un prestataire',
            'description' => null,
            'due_date' => null,
            'source' => ItemSource::User,
            'position' => 0,
        ];
    }
}
