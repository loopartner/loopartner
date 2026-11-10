<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /providers : filtres de la recherche publique de prestataires.
 */
class SearchProvidersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $country = $this->query('country');
        if (is_string($country)) {
            $this->merge(['country' => strtoupper(trim($country))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Un paramètre vide (?q=) devient null et n'applique aucun filtre.
            'category' => ['nullable', 'string', 'max:100', new ActiveServiceCategory],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            'city' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            ...Pagination::rules(),
        ];
    }
}
