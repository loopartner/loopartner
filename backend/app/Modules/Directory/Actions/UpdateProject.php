<?php

namespace App\Modules\Directory\Actions;

use App\Models\ServiceCategory;
use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\ProjectAttributes;
use App\Modules\Directory\Support\ProjectRules;
use Illuminate\Validation\ValidationException;

/**
 * Modifie partiellement un projet, quel que soit son statut. Un projet publié le reste et
 * garde ses dates de publication et d'expiration.
 */
class UpdateProject
{
    /**
     * @param  array<string, mixed>  $data  données validées (champs présents uniquement)
     *
     * @throws ValidationException
     */
    public function handle(Project $project, array $data): Project
    {
        $project->fill(ProjectAttributes::fromInput($data));

        // Comparaison sur les valeurs finales : un PATCH peut n'envoyer qu'une des deux bornes.
        ProjectRules::ensureBudgetOrder($project->budget_min, $project->budget_max);

        if (isset($data['category']) && is_string($data['category'])) {
            $project->forceFill([
                'service_category_id' => ServiceCategory::query()->where('slug', $data['category'])->value('id'),
            ]);
        }

        $project->save();

        return $project->load('category');
    }
}
