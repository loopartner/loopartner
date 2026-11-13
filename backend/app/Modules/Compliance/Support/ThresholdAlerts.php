<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ThresholdAlert;

/**
 * Enregistre les alertes de seuil d'un rapport (ThresholdReport) : chaque palier atteint par le
 * chiffre d'affaires réel, et le dépassement projeté, une seule fois par seuil et par année
 * (index unique ; insertOrIgnore résiste aux saisies simultanées). Le récapitulatif par email
 * envoie ensuite celles qui ne l'ont pas encore été.
 */
final class ThresholdAlerts
{
    /**
     * @param  list<array<string, mixed>>  $report
     */
    public static function record(Company $company, int $year, array $report): void
    {
        $now = now();
        $rows = [];

        foreach ($report as $threshold) {
            foreach ((array) $threshold['levels'] as $level) {
                if (is_array($level) && ($level['reached'] ?? false) === true) {
                    $rows[] = self::row($company, (string) $threshold['code'], $year, ThresholdAlert::REACHED, (int) $level['level'], $now);
                }
            }

            $projection = $threshold['projection'] ?? null;

            if (is_array($projection) && ($projection['exceeds'] ?? false) === true) {
                $rows[] = self::row($company, (string) $threshold['code'], $year, ThresholdAlert::PROJECTED, 100, $now);
            }
        }

        if ($rows !== []) {
            ThresholdAlert::query()->insertOrIgnore($rows);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Company $company, string $code, int $year, string $kind, int $level, mixed $now): array
    {
        return [
            'company_id' => $company->id,
            'rule_code' => $code,
            'year' => $year,
            'kind' => $kind,
            'level' => $level,
            'reached_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
