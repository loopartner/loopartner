<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Exceptions\PremiumFeatureRequired;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\CalendarGenerator;
use App\Modules\Compliance\Support\CompanyProfile;
use App\Modules\Compliance\Support\CompanyRules;
use App\Modules\Compliance\Support\Entitlements;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Questionnaire de démarrage : crée l'entreprise et génère son calendrier personnalisé à partir
 * des règles vérifiées, dans la même transaction.
 *
 * Plafond : compliance.max_companies_free (1) sans premium (403 premium_required au-delà),
 * compliance.max_companies_premium (5) avec premium (409). Compté sous verrou de la ligne users.
 */
class CreateCompany
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  array<string, mixed>  $data  données validées par StoreCompanyRequest
     *
     * @throws PremiumFeatureRequired
     * @throws ConflictHttpException
     */
    public function handle(User $user, array $data): Company
    {
        $country = (string) $data['country'];
        $profile = CompanyProfile::check($country, [
            'legal_form' => (string) $data['legal_form'],
            'activity_types' => array_values(array_map('strval', (array) $data['activity_types'])),
            'vat_regime' => (string) $data['vat_regime'],
            'options' => (array) ($data['options'] ?? []),
        ]);

        return DB::transaction(function () use ($user, $data, $country, $profile): Company {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            $count = Company::query()->where('user_id', $user->id)->count();

            if ($count >= $this->entitlements->maxCompanies($user->id)) {
                if (! $this->entitlements->isPremium($user->id)) {
                    $this->entitlements->require('multiple_companies', $user->id);
                }

                throw new ConflictHttpException('Vous avez atteint le nombre maximal d\'entreprises suivies.');
            }

            $company = new Company;
            $company->forceFill([
                ...$profile,
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'name' => (string) $data['name'],
                'country' => $country,
                'created_on' => (string) $data['created_on'],
                'fiscal_year_end' => (string) ($data['fiscal_year_end'] ?? '12-31'),
                'registration_number' => CompanyRules::compact(is_string($data['registration_number'] ?? null) ? $data['registration_number'] : null),
                'vat_number' => CompanyRules::compact(is_string($data['vat_number'] ?? null) ? $data['vat_number'] : null),
            ])->save();

            CalendarGenerator::refresh($company);

            SecurityLog::record('compliance.company_created', ['user_id' => $user->id, 'company' => $company->uuid, 'country' => $country]);

            return $company;
        });
    }
}
