<?php

namespace App\Modules\Workspaces\Http\Requests;

/**
 * POST …/copilot/suggestions/{suggestion}/apply : {"steps": [index, …]} facultatif (feuille de
 * route : étapes retenues ; toutes par défaut).
 */
class ApplySuggestionRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'steps' => ['sometimes', 'array', 'min:1', 'max:50'],
            'steps.*' => ['required', 'integer', 'min:0', 'max:49', 'distinct'],
        ];
    }
}
