<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\CompanyRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/compliance/companies : questionnaire de démarrage (prestataires et entrepreneurs).
 * Les réponses sont ensuite vérifiées contre les règles du pays (CompanyProfile).
 */
class StoreCompanyRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CompanyRules::rules(false, strtoupper((string) $this->input('country')));
    }
}
