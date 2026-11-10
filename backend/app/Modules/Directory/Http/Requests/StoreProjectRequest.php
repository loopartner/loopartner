<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Models\Project;
use App\Modules\Directory\Support\ProjectRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/projects : création d'un projet, toujours en brouillon.
 * Le texte est brut : le HTML est refusé.
 */
class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) ?? false;
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
        return ProjectRules::rules(partial: false);
    }
}
