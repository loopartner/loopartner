<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Support\RuleContent;

/**
 * POST …/companies/{company}/journeys : démarrer un parcours type (code d'une règle journey
 * vérifiée du pays de l'entreprise).
 */
class StartJourneyRequest extends CompanyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'journey' => ['required', 'string', 'max:100', 'regex:'.RuleContent::CODE_PATTERN],
        ];
    }
}
