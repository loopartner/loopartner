<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\RevenueEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Chiffre d'affaires d'une entreprise pour une année civile, en centimes. Les montants sont
 * chiffrés en base : ils sont additionnés en PHP, sur des entiers (aucun flottant, aucun
 * arrondi).
 */
final class RevenueLedger
{
    /**
     * @param  Collection<int, RevenueEntry>  $entries
     */
    private function __construct(public readonly int $year, public readonly Collection $entries) {}

    public static function for(Company $company, int $year): self
    {
        $entries = RevenueEntry::query()
            ->where('company_id', $company->id)
            ->whereDate('period', '>=', sprintf('%d-01-01', $year))
            ->whereDate('period', '<=', sprintf('%d-12-01', $year))
            ->orderBy('period')
            ->orderBy('activity_type')
            ->get();

        return new self($year, $entries);
    }

    /**
     * Total des types d'activité donnés (tous si la liste est vide), sur les mois jusqu'à
     * $until inclus (tous si null).
     *
     * @param  list<string>  $activities
     */
    public function total(array $activities = [], ?CarbonImmutable $until = null): int
    {
        return (int) $this->entries
            ->filter(fn (RevenueEntry $entry): bool => ($activities === [] || in_array($entry->activity_type, $activities, true))
                && ($until === null || $entry->period->toDateString() <= $until->toDateString()))
            ->sum(fn (RevenueEntry $entry): int => $entry->amount);
    }

    /**
     * Mois saisis : période « AAAA-MM », montants par type d'activité et total.
     *
     * @return list<array{period: string, lines: array<string, int>, total: int}>
     */
    public function months(): array
    {
        return $this->entries
            ->groupBy(fn (RevenueEntry $entry): string => $entry->period->format('Y-m'))
            ->map(fn (Collection $entries, string $period): array => [
                'period' => $period,
                'lines' => $entries->mapWithKeys(fn (RevenueEntry $entry): array => [$entry->activity_type => $entry->amount])->all(),
                'total' => (int) $entries->sum(fn (RevenueEntry $entry): int => $entry->amount),
            ])
            ->values()
            ->all();
    }

    /**
     * Totaux par type d'activité.
     *
     * @return array<string, int>
     */
    public function byActivity(): array
    {
        return $this->entries
            ->groupBy('activity_type')
            ->map(fn (Collection $entries): int => (int) $entries->sum(fn (RevenueEntry $entry): int => $entry->amount))
            ->all();
    }
}
