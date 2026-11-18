<?php

namespace App\Modules\Workspaces\Http\Requests;

use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;

/**
 * POST /me/workspaces/{uuid}/directory-draft : brouillon de projet Directory créé à partir du
 * workspace (catégorie obligatoire ; titre, description et pays repris du workspace s'ils sont
 * absents). Les règles complètes du projet sont appliquées par Directory (422).
 */
class DirectoryDraftRequest extends WorkspaceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', 'max:100', new ActiveServiceCategory],
            'title' => ['sometimes', 'string', 'max:150', new NoHtml, new NoInvisibleCharacters],
            'description' => ['sometimes', 'string', 'max:5000', new NoHtml, new NoInvisibleCharacters],
            'country' => ['sometimes', 'string', 'size:2', new CountryCode],
            'status' => ['prohibited'],
            'publish' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('country'))) {
            $this->merge(['country' => strtoupper(trim($this->input('country')))]);
        }
    }
}
