<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Enums\PricingMode;
use App\Rules\ActiveServiceCategory;
use App\Rules\CountryCode;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /offers et GET /providers/{slug}/offers : recherche publique. Un paramètre vide est
 * ignoré.
 */
class SearchOffersRequest extends FormRequest
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
            'category' => ['nullable', 'string', 'max:100', new ActiveServiceCategory],
            'country' => ['nullable', 'string', 'size:2', new CountryCode],
            'city' => ['nullable', 'string', 'max:100'],
            'pricing_mode' => ['nullable', 'string', Rule::in(PricingMode::values())],
            'q' => ['nullable', 'string', 'min:2', 'max:100'],
            ...Pagination::rules(),
        ];
    }
}
