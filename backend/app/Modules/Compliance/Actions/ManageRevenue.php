<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Models\RevenueEntry;
use App\Modules\Compliance\Support\Entitlements;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Modules\Compliance\Support\ThresholdAlerts;
use App\Modules\Compliance\Support\ThresholdReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saisie du chiffre d'affaires d'un mois (PUT …/revenue/{AAAA-MM}) : les montants envoyés
 * remplacent ceux du mois (un type d'activité absent est retiré). Puis les seuils de l'année
 * sont recalculés et les nouveaux paliers atteints enregistrés (ThresholdAlerts).
 *
 * Mois accepté : à partir du mois de création de l'entreprise, jusqu'au mois en cours (fuseau
 * du pays), et au plus compliance.revenue.max_years_back années en arrière (422 sinon).
 * Entreprise verrouillée : 403 premium_required.
 */
class ManageRevenue
{
    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  array<string, int>  $lines  centimes par type d'activité
     * @return int année du mois saisi
     *
     * @throws ValidationException
     */
    public function save(Company $company, string $period, array $lines): int
    {
        $this->entitlements->assertWritable($company);
        $month = $this->month($company, $period);

        // Deux saisies simultanées du même mois passent l'une après l'autre (verrou de la ligne
        // de l'entreprise) : sans lui, chacune insérait les lignes absentes et la seconde
        // heurtait l'index unique (company_id, period, activity_type). Si l'index refuse
        // malgré tout une insertion, la seconde tentative met à jour les lignes créées
        // entre-temps (le mois envoyé remplace le mois enregistré).
        retry(2, fn () => DB::transaction(function () use ($company, $month, $lines): void {
            Company::query()->whereKey($company->id)->lockForUpdate()->first();

            RevenueEntry::query()
                ->where('company_id', $company->id)
                ->whereDate('period', $month->toDateString())
                ->whereNotIn('activity_type', array_keys($lines))
                ->delete();

            foreach ($lines as $activity => $amount) {
                $entry = RevenueEntry::query()
                    ->where('company_id', $company->id)
                    ->whereDate('period', $month->toDateString())
                    ->where('activity_type', $activity)
                    ->first() ?? new RevenueEntry;

                $entry->forceFill([
                    'company_id' => $company->id,
                    'period' => $month->toDateString(),
                    'activity_type' => $activity,
                    'amount' => $amount,
                ])->save();
            }
        }), 0, fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException);

        $levels = $this->entitlements->thresholdLevels(ComplianceSettings::for($company->user_id));
        ThresholdAlerts::record($company, $month->year, ThresholdReport::for($company, $month->year, $levels, 'fr'));

        return $month->year;
    }

    /**
     * @throws ValidationException
     */
    public function delete(Company $company, string $period): void
    {
        $this->entitlements->assertWritable($company);
        $month = $this->month($company, $period);

        RevenueEntry::query()->where('company_id', $company->id)->whereDate('period', $month->toDateString())->delete();
    }

    /**
     * Premier jour du mois demandé, dans les bornes acceptées.
     *
     * @throws ValidationException
     */
    private function month(Company $company, string $period): CarbonImmutable
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $period);
        $current = CarbonImmutable::now(RuleCatalog::timezone($company->country))->startOfMonth();
        $current = CarbonImmutable::create($current->year, $current->month, 1);
        $created = CarbonImmutable::parse($company->created_on->toDateString())->startOfMonth();
        $oldest = $current->subYears((int) config('compliance.revenue.max_years_back'));

        if ($month === null || $month->greaterThan($current) || $month->lessThan($created) || $month->lessThan($oldest)) {
            throw ValidationException::withMessages([
                'period' => 'Le mois doit être compris entre le mois de création de l\'entreprise et le mois en cours.',
            ]);
        }

        return $month;
    }
}
