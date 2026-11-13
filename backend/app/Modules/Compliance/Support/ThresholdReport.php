<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceRule;
use Carbon\CarbonImmutable;

/**
 * Situation d'une entreprise face aux seuils de chiffre d'affaires de son pays (règles threshold
 * VÉRIFIÉES en vigueur au 1er janvier de l'année, qui s'appliquent à son profil).
 *
 * Calculs en entiers (centimes), sans arrondi caché ; chaque arrondi est par défaut (floor) et
 * annoncé dans la réponse :
 * - seuil proratisé l'année de création si la règle le prévoit :
 *   seuil × jours d'activité dans l'année ÷ jours de l'année ;
 * - ratio en points de base (8412 = 84,12 %) : chiffre d'affaires × 10 000 ÷ seuil ;
 * - projection linéaire : chiffre d'affaires des mois complets écoulés × jours d'activité de
 *   l'année ÷ jours couverts. Aucune projection avant la fin du premier mois complet.
 *
 * Le code compare des montants ; les conséquences d'un dépassement sont le texte de la règle.
 */
final class ThresholdReport
{
    /**
     * @param  list<int>  $levels  paliers d'alerte, en pourcentage
     * @return list<array<string, mixed>>
     */
    public static function for(Company $company, int $year, array $levels, string $locale): array
    {
        $ledger = RevenueLedger::for($company, $year);
        $today = CarbonImmutable::parse(CarbonImmutable::now(RuleCatalog::timezone($company->country))->toDateString());
        $yearStart = CarbonImmutable::create($year, 1, 1);
        $yearEnd = CarbonImmutable::create($year, 12, 31);
        $created = CarbonImmutable::parse($company->created_on->toDateString());
        $activityStart = $created->greaterThan($yearStart) ? $created : $yearStart;

        if ($activityStart->greaterThan($yearEnd)) {
            return [];
        }

        $activeDays = (int) $activityStart->diffInDays($yearEnd) + 1;
        $coveredUntil = self::coveredUntil($year, $today, $activityStart);
        $currency = RuleCatalog::currency($company->country);
        $report = [];

        $rules = ComplianceRule::query()
            ->usable()
            ->where('country', $company->country)
            ->where('kind', RuleKind::Threshold->value)
            ->validOn($yearStart)
            ->orderBy('code')
            ->get();

        foreach ($rules as $rule) {
            if (! RuleConditions::matches($rule->conditions, $company)) {
                continue;
            }

            $base = $rule->payload['amount'] ?? null;

            if (! is_int($base) || $base <= 0) {
                continue;
            }

            $activities = array_values(array_intersect((array) ($rule->payload['activity_types'] ?? []), $company->activity_types));
            $prorated = (bool) ($rule->payload['prorate_first_year'] ?? false) && $created->year === $year && $created->greaterThan($yearStart);
            $threshold = $prorated ? intdiv($base * $activeDays, $yearStart->daysInYear) : $base;
            $revenue = $activities === [] ? 0 : $ledger->total($activities);
            $ratio = intdiv($revenue * 10_000, $threshold);

            $projection = null;

            if ($coveredUntil !== null && $activities !== []) {
                $coveredDays = (int) $activityStart->diffInDays($coveredUntil) + 1;
                $projected = intdiv($ledger->total($activities, $coveredUntil->startOfMonth()) * $activeDays, $coveredDays);
                $projection = [
                    'amount' => $projected,
                    'ratio_basis_points' => intdiv($projected * 10_000, $threshold),
                    'exceeds' => $projected > $threshold,
                    'covered_until' => $coveredUntil->toDateString(),
                    'method' => 'linear',
                ];
            }

            $report[] = [
                'code' => $rule->code,
                'title' => $rule->titleIn($locale),
                'consequence' => ComplianceRule::translate(is_array($rule->payload['consequence'] ?? null) ? $rule->payload['consequence'] : null, $locale),
                'activity_types' => $activities,
                'currency' => $currency,
                'threshold' => [
                    'amount' => $threshold,
                    'base_amount' => $base,
                    'prorated' => $prorated,
                    'active_days' => $activeDays,
                ],
                'revenue' => $revenue,
                'ratio_basis_points' => $ratio,
                'levels' => array_map(fn (int $level): array => ['level' => $level, 'reached' => $ratio >= $level * 100], $levels),
                'projection' => $projection,
                'rounding' => 'floor',
                'source' => ['url' => $rule->source_url, 'label' => $rule->source_label],
                'verified_at' => $rule->last_verified_at?->toIso8601ZuluString(),
            ];
        }

        return $report;
    }

    /**
     * Dernier jour du dernier mois complet de l'année (jusqu'à aujourd'hui), ou null si aucun
     * mois complet d'activité n'est écoulé.
     */
    private static function coveredUntil(int $year, CarbonImmutable $today, CarbonImmutable $activityStart): ?CarbonImmutable
    {
        $end = $today->year > $year
            ? CarbonImmutable::create($year, 12, 31)
            : ($today->year === $year ? $today->startOfMonth()->subDay() : null);

        if ($end === null || $end->lessThan($activityStart->endOfMonth()->startOfDay())) {
            return null;
        }

        return $end;
    }
}
