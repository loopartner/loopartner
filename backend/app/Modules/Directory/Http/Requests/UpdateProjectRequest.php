<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\ProjectRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /me/projects/{uuid} : modification partielle. Un champ absent reste inchangé.
 *
 * L'autorisation passe avant la validation : le projet d'un autre utilisateur donne 404,
 * quelles que soient les données envoyées.
 */
class UpdateProjectRequest extends FormRequest
{
    private ?Project $project = null;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->project());
    }

    protected function prepareForValidation(): void
    {
        $this->replace(ProjectRules::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ProjectRules::rules(partial: true);
    }

    /**
     * Projet visé par l'URL (404 s'il n'existe pas).
     */
    public function project(): Project
    {
        if ($this->project === null) {
            $project = Project::query()->where('uuid', (string) $this->route('uuid'))->first();
            abort_if($project === null, 404, 'Projet introuvable.');
            $this->project = $project;
        }

        return $this->project;
    }
}
