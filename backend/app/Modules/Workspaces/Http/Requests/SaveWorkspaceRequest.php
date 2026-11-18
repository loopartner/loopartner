<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Modules\Workspaces\Support\ContentRules;

/**
 * POST /me/workspaces (création, modèle facultatif) et PATCH /me/workspaces/{uuid}.
 */
class SaveWorkspaceRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ContentRules::workspace($this->partial());
    }

    protected function prepareForValidation(): void
    {
        foreach (['country', 'currency'] as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $this->merge([$field => strtoupper(trim($value))]);
            }
        }
    }
}
