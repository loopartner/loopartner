<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Models\Company;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Base des requêtes qui visent une entreprise (/me/compliance/companies/{company}/…).
 *
 * L'autorisation passe avant la validation : l'entreprise d'un autre utilisateur donne 404,
 * quelles que soient les données envoyées (CompanyPolicy).
 */
abstract class CompanyRequest extends FormRequest
{
    private ?Company $company = null;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->company());
    }

    /**
     * Entreprise visée par l'URL (404 si elle n'existe pas).
     */
    public function company(): Company
    {
        if ($this->company === null) {
            $company = Company::query()->where('uuid', (string) $this->route('company'))->first();
            abort_if($company === null, 404, 'Entreprise introuvable.');
            $this->company = $company;
        }

        return $this->company;
    }
}
