<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\Company;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;

/**
 * Suppression d'une entreprise et de toutes ses données (échéances, tâches, parcours, chiffre
 * d'affaires, alertes : clés étrangères en cascade). Possible même pour une entreprise
 * verrouillée. La route exige une confirmation récente du mot de passe (password.confirm).
 */
class DeleteCompany
{
    public function handle(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            SecurityLog::record('compliance.company_deleted', ['user_id' => $company->user_id, 'company' => $company->uuid]);
            $company->delete();
        });
    }
}
