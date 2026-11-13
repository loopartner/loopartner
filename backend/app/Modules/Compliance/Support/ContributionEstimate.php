<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use Carbon\CarbonImmutable;

/**
 * Estimation (premium) des cotisations et impôts à mettre de côté sur le chiffre d'affaires
 * saisi de l'année, à partir des seuls taux VÉRIFIÉS (règles rate) qui s'appliquent au profil
 * de l'entreprise. Clairement présentée comme une estimation (is_estimate) : elle ignore les
 * minimums, abattements, exonérations et régularisations que la règle ne décrit pas.
 *
 * Montant de chaque ligne : base × points de base ÷ 10 000, arrondi par défaut au centime.
 * Sans taux vérifié pour ce profil (sociétés à l'impôt sur les sociétés, par exemple) :
 * available = false, aucune approximation.
 */
final class ContributionEstimate
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, int $year, string $locale): array
    {
        $ledger = RevenueLedger::for($company, $year);
        $lines = [];

        $rules = ComplianceRule::query()
            ->usable()
            ->where('country', $company->country)
            ->where('kind', RuleKind::Rate->value)
            ->validOn(CarbonImmutable::create($year, 1, 1))
            ->orderBy('code')
            ->get();

        foreach ($rules as $rule) {
            $basisPoints = $rule->payload['basis_points'] ?? null;

            if (! is_int($basisPoints) || ! RuleConditions::matches($rule->conditions, $company)) {
                continue;
            }

            $activities = array_values(array_intersect((array) ($rule->payload['activity_types'] ?? []), $company->activity_types));

            if ($activities === []) {
                continue;
            }

            $base = $ledger->total($activities);

            $lines[] = [
                'code' => $rule->code,
                'title' => $rule->titleIn($locale),
                'category' => (string) ($rule->payload['category'] ?? 'other'),
                'activity_types' => $activities,
                'basis_points' => $basisPoints,
                'base' => $base,
                'amount' => intdiv($base * $basisPoints, 10_000),
                'source' => ['url' => $rule->source_url, 'label' => $rule->source_label],
                'verified_at' => $rule->last_verified_at?->toIso8601ZuluString(),
            ];
        }

        return [
            'year' => $year,
            'currency' => RuleCatalog::currency($company->country),
            'is_estimate' => true,
            'available' => $lines !== [],
            'lines' => $lines,
            'total' => array_sum(array_column($lines, 'amount')),
            'rounding' => 'floor',
            'notice' => $locale === 'en'
                ? 'Estimate based on the revenue entered and on verified rates only. It ignores minimums, allowances, exemptions and adjustments: check the amounts with your accountant or the authorities.'
                : 'Estimation calculée sur le chiffre d\'affaires saisi et les seuls taux vérifiés. Elle ignore les minimums, abattements, exonérations et régularisations : vérifiez les montants avec votre expert-comptable ou l\'administration.',
        ];
    }
}
