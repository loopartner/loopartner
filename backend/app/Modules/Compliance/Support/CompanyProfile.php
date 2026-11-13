<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\ComplianceOption;
use App\Modules\Compliance\Models\ComplianceRule;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Réponses du questionnaire, vérifiées contre les règles VÉRIFIÉES du pays pour l'année en
 * cours : le statut juridique doit être proposé, et les activités, le régime de TVA et les
 * options doivent être ceux que ce statut accepte (422 sinon, sur le champ concerné).
 */
final class CompanyProfile
{
    /**
     * @param  array{legal_form: string, activity_types: list<string>, vat_regime: string, options: array<string, mixed>}  $profile
     * @return array{legal_form_code: string, activity_types: list<string>, vat_regime: string, options: array<string, bool|string>}
     *
     * @throws ValidationException
     */
    public static function check(string $country, array $profile): array
    {
        $year = CarbonImmutable::now(RuleCatalog::timezone($country))->year;

        if (! RuleCatalog::isAvailable($country, $year)) {
            throw ValidationException::withMessages(['country' => 'Ce pays n\'est pas encore pris en charge.']);
        }

        $legalForm = RuleCatalog::legalFormsThroughout($country, $year)
            ->first(fn (ComplianceRule $rule): bool => $rule->code === $profile['legal_form']);

        if ($legalForm === null) {
            throw ValidationException::withMessages(['legal_form' => 'Ce statut juridique n\'est pas proposé pour ce pays.']);
        }

        $payload = $legalForm->payload;
        $activities = array_values(array_unique($profile['activity_types']));

        if (array_diff($activities, (array) ($payload['activity_types'] ?? [])) !== []) {
            throw ValidationException::withMessages(['activity_types' => 'Ce type d\'activité n\'est pas possible avec ce statut juridique.']);
        }

        if (! in_array($profile['vat_regime'], (array) ($payload['vat_regimes'] ?? []), true)) {
            throw ValidationException::withMessages(['vat_regime' => 'Ce régime de TVA n\'est pas possible avec ce statut juridique.']);
        }

        $offered = (array) ($payload['options'] ?? []);
        $options = [];

        foreach ($profile['options'] as $key => $value) {
            if (! in_array($key, $offered, true)) {
                throw ValidationException::withMessages(["options.{$key}" => 'Cette option n\'est pas proposée pour ce statut juridique.']);
            }
        }

        foreach ($offered as $key) {
            $option = ComplianceOption::tryFrom((string) $key);

            if ($option === null) {
                continue;
            }

            $value = $profile['options'][$option->value] ?? null;
            $options[$option->value] = $value !== null && $option->isValid($value) ? $value : $option->defaultValue();
        }

        return [
            'legal_form_code' => $legalForm->code,
            'activity_types' => $activities,
            'vat_regime' => $profile['vat_regime'],
            'options' => $options,
        ];
    }
}
