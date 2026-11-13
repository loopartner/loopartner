<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\ComplianceOption;
use App\Modules\Compliance\Models\Company;

/**
 * Une règle s'applique-t-elle à une entreprise ? Comparaisons simples sur une liste fermée de
 * critères (pas de langage d'expression) :
 *
 * - legal_forms : le statut de l'entreprise est dans la liste ;
 * - activity_types : au moins une activité de l'entreprise est dans la liste ;
 * - vat_regimes : le régime de TVA est dans la liste ;
 * - options : chaque option a la valeur demandée (valeur par défaut si non répondue).
 *
 * Une liste vide veut dire « toutes ».
 */
final class RuleConditions
{
    /**
     * @param  array<string, mixed>  $conditions
     */
    public static function matches(array $conditions, Company $company): bool
    {
        $legalForms = (array) ($conditions['legal_forms'] ?? []);
        $activities = (array) ($conditions['activity_types'] ?? []);
        $vatRegimes = (array) ($conditions['vat_regimes'] ?? []);

        if ($legalForms !== [] && ! in_array($company->legal_form_code, $legalForms, true)) {
            return false;
        }

        if ($activities !== [] && array_intersect($company->activity_types, $activities) === []) {
            return false;
        }

        if ($vatRegimes !== [] && ! in_array($company->vat_regime, $vatRegimes, true)) {
            return false;
        }

        foreach ((array) ($conditions['options'] ?? []) as $key => $expected) {
            $option = ComplianceOption::tryFrom((string) $key);

            if ($option === null || (self::optionValue($company, $option) !== $expected)) {
                return false;
            }
        }

        return true;
    }

    public static function optionValue(Company $company, ComplianceOption $option): bool|string
    {
        $value = $company->option($option->value);

        return $value !== null && $option->isValid($value) ? $value : $option->defaultValue();
    }
}
