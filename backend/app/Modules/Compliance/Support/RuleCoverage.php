<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use Carbon\CarbonImmutable;

/**
 * Couverture des règles pour une année (mise à jour annuelle) : pour chaque pays, ce qu'il faut
 * vérifier avant le 1er janvier pour que les utilisateurs gardent un calendrier complet.
 *
 * - missing : codes vérifiés en vigueur le 31 décembre de l'année précédente, sans version
 *   vérifiée en vigueur le 1er janvier de l'année visée.
 * - stale : codes couverts par une règle sans date de fin, mais vérifiée avant la date
 *   compliance.coverage.stale_before de l'année précédente (les seuils et taux changent souvent
 *   au 1er janvier) : à revérifier.
 * - drafts : brouillons en vigueur le 1er janvier de l'année visée, en attente de vérification.
 * - available : le pays sera proposé aux nouvelles entreprises cette année-là.
 *
 * Les règles de démonstration ne comptent jamais.
 */
final class RuleCoverage
{
    /**
     * @return list<array{country: string, available: bool, missing: list<string>, stale: list<string>, drafts: int}>
     */
    public static function report(int $year): array
    {
        $previousEnd = CarbonImmutable::create($year - 1, 12, 31);
        $start = CarbonImmutable::create($year, 1, 1);
        $staleBefore = CarbonImmutable::parse(($year - 1).'-'.config('compliance.coverage.stale_before'));

        $countries = collect(RuleCatalog::configuredCountries())
            ->merge(ComplianceRule::query()->where('is_demo', false)->distinct()->pluck('country'))
            ->unique()
            ->sort()
            ->values();

        return $countries->map(function (string $country) use ($year, $previousEnd, $start, $staleBefore): array {
            $verified = fn () => ComplianceRule::query()
                ->where('country', $country)
                ->where('is_demo', false)
                ->where('status', RuleStatus::Verified->value);

            $expected = $verified()->validOn($previousEnd)->pluck('code')->unique();
            $covering = $verified()->validOn($start)->get();
            $covered = $covering->pluck('code')->unique();

            $stale = $covering
                ->filter(fn (ComplianceRule $rule): bool => $rule->valid_until === null
                    && ($rule->last_verified_at === null || $rule->last_verified_at->lessThan($staleBefore)))
                ->pluck('code')
                ->unique();

            return [
                'country' => $country,
                'available' => RuleCatalog::isAvailable($country, $year),
                'missing' => $expected->diff($covered)->sort()->values()->all(),
                'stale' => $stale->sort()->values()->all(),
                'drafts' => ComplianceRule::query()
                    ->where('country', $country)
                    ->where('is_demo', false)
                    ->where('status', RuleStatus::Draft->value)
                    ->validOn($start)
                    ->count(),
            ];
        })->all();
    }

    /**
     * Le rapport demande-t-il une action (règle manquante, à revérifier, ou pays indisponible) ?
     *
     * @param  list<array{country: string, available: bool, missing: list<string>, stale: list<string>, drafts: int}>  $report
     */
    public static function needsAttention(array $report): bool
    {
        foreach ($report as $country) {
            if (! $country['available'] || $country['missing'] !== [] || $country['stale'] !== []) {
                return true;
            }
        }

        return false;
    }
}
