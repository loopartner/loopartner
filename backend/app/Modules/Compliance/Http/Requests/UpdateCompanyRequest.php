<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Support\CompanyRules;

/**
 * PATCH /me/compliance/companies/{company} : modification partielle du profil. Un champ absent
 * reste inchangé ; le pays ne change jamais (422).
 */
class UpdateCompanyRequest extends CompanyRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return CompanyRules::rules(true, $this->company()->country);
    }
}
