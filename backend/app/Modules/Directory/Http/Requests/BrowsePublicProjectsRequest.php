<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Support\ProjectRules;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /public/projects : filtres de la vitrine publique (décision 71), ouverte à tout visiteur.
 * Moins de filtres que GET /projects : catégorie et pays seulement.
 */
class BrowsePublicProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(ProjectRules::normalize($this->query()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'category' => ['nullable', 'string', 'max:100', new ActiveServiceCategory],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            ...Pagination::rules(),
        ];
    }
}
