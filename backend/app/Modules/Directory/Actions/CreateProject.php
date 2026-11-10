<?php

namespace App\Modules\Directory\Actions;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\ProjectAttributes;
use App\Modules\Directory\Support\ProjectRules;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Crée un projet en brouillon pour son propriétaire.
 */
class CreateProject
{
    /**
     * @param  array{title: string, description: string, category: string, budget_min?: int|string|null, budget_max?: int|string|null, currency?: string, country: string, city?: string|null, remote_ok?: bool|int|string, desired_start_date?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function handle(User $user, array $data): Project
    {
        $project = new Project(ProjectAttributes::fromInput($data));

        ProjectRules::ensureBudgetOrder($project->budget_min, $project->budget_max);

        $project->forceFill([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'service_category_id' => ServiceCategory::query()->where('slug', $data['category'])->value('id'),
            'status' => ProjectStatus::Draft,
        ])->save();

        return $project->load('category');
    }
}
