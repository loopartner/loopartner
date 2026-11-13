<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Exceptions\PremiumFeatureRequired;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\CalendarGenerator;
use App\Modules\Compliance\Support\CompanyProfile;
use App\Modules\Compliance\Support\CompanyRules;
use App\Modules\Compliance\Support\Entitlements;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Modification du profil d'une entreprise (un champ absent reste inchangé ; le pays ne change
 * jamais). Si le statut, les activités, le régime de TVA, les options, la date de création ou
 * la clôture changent, le profil est revérifié contre les règles et les échéances FUTURES sont
 * recalculées, sans toucher à ce que l'utilisateur a déjà fait ou ignoré.
 *
 * Entreprise verrouillée (en trop après la fin du premium) : 403 premium_required.
 */
class UpdateCompany
{
    private const PROFILE_FIELDS = ['legal_form', 'activity_types', 'vat_regime', 'options'];

    private const CALENDAR_FIELDS = ['legal_form', 'activity_types', 'vat_regime', 'options', 'created_on', 'fiscal_year_end'];

    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  array<string, mixed>  $data  données validées par UpdateCompanyRequest
     *
     * @throws PremiumFeatureRequired
     * @throws ValidationException
     */
    public function handle(Company $company, array $data): Company
    {
        $this->entitlements->assertWritable($company);

        $attributes = [];

        if (Arr::hasAny($data, self::PROFILE_FIELDS)) {
            $attributes = CompanyProfile::check($company->country, [
                'legal_form' => (string) ($data['legal_form'] ?? $company->legal_form_code),
                'activity_types' => array_values(array_map('strval', (array) ($data['activity_types'] ?? $company->activity_types))),
                'vat_regime' => (string) ($data['vat_regime'] ?? $company->vat_regime),
                'options' => (array) ($data['options'] ?? $company->options),
            ]);
        }

        foreach (['name', 'created_on', 'fiscal_year_end'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = (string) $data[$field];
            }
        }

        foreach (['registration_number', 'vat_number'] as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = CompanyRules::compact(is_string($data[$field]) ? $data[$field] : null);
            }
        }

        return DB::transaction(function () use ($company, $attributes, $data): Company {
            $company->forceFill($attributes)->save();

            if (Arr::hasAny($data, self::CALENDAR_FIELDS)) {
                CalendarGenerator::refresh($company);
            }

            return $company;
        });
    }
}
