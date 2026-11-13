<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Models\ComplianceRule;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lecture des règles montrées aux utilisateurs : uniquement des règles vérifiées (et, hors du
 * poste local, jamais de règle de démonstration). Aucune règle en brouillon n'en sort.
 */
final class RuleCatalog
{
    /**
     * Pays pris en charge par l'application (config/compliance.php).
     *
     * @return list<string>
     */
    public static function configuredCountries(): array
    {
        return array_keys((array) config('compliance.countries'));
    }

    public static function currency(string $country): string
    {
        return (string) config("compliance.countries.{$country}.currency");
    }

    public static function timezone(string $country): string
    {
        return (string) config("compliance.countries.{$country}.timezone", 'UTC');
    }

    /**
     * Un pays est proposé s'il est pris en charge et qu'au moins un statut juridique vérifié
     * couvre toute l'année.
     */
    public static function isAvailable(string $country, int $year): bool
    {
        return in_array($country, self::configuredCountries(), true)
            && self::legalFormsThroughout($country, $year)->isNotEmpty();
    }

    /**
     * @return list<string>
     */
    public static function availableCountries(int $year): array
    {
        return array_values(array_filter(self::configuredCountries(), fn (string $country): bool => self::isAvailable($country, $year)));
    }

    /**
     * Statuts juridiques vérifiés en vigueur toute l'année.
     *
     * @return Collection<int, ComplianceRule>
     */
    public static function legalFormsThroughout(string $country, int $year): Collection
    {
        return ComplianceRule::query()
            ->usable()
            ->where('country', $country)
            ->where('kind', RuleKind::LegalForm->value)
            ->validThroughout(CarbonImmutable::create($year, 1, 1), CarbonImmutable::create($year, 12, 31))
            ->orderBy('code')
            ->get();
    }

    /**
     * Règle vérifiée d'un code en vigueur ce jour-là (au plus une : VerifyRule interdit les
     * chevauchements).
     */
    public static function find(string $country, string $code, CarbonInterface $day): ?ComplianceRule
    {
        return ComplianceRule::query()
            ->usable()
            ->where('country', $country)
            ->where('code', $code)
            ->validOn($day)
            ->first();
    }

    /**
     * Règles vérifiées d'un type qui chevauchent une période.
     *
     * @return Collection<int, ComplianceRule>
     */
    public static function overlapping(string $country, RuleKind $kind, CarbonInterface $from, CarbonInterface $until): Collection
    {
        return ComplianceRule::query()
            ->usable()
            ->where('country', $country)
            ->where('kind', $kind->value)
            ->overlapping($from, $until)
            ->orderBy('code')
            ->orderBy('valid_from')
            ->get();
    }
}
