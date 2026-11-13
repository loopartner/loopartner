<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Rules\CountryCode;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Liste des règles pour l'administration (GET /admin/compliance/rules) : filtres facultatifs
 * (pays, type, statut, code, année de validité), pagination standard.
 */
class ListRulesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'country' => ['sometimes', 'nullable', 'string', new CountryCode],
            'kind' => ['sometimes', 'nullable', 'string', Rule::enum(RuleKind::class)],
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(RuleStatus::class)],
            'code' => ['sometimes', 'nullable', 'string', 'max:100'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            ...Pagination::rules(),
        ];
    }
}
