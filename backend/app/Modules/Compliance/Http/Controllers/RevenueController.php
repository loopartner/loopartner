<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Actions\ManageRevenue;
use App\Modules\Compliance\Http\Requests\SaveRevenueRequest;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Models\ComplianceSettings;
use App\Modules\Compliance\Support\ContributionEstimate;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\Entitlements;
use App\Modules\Compliance\Support\RevenueLedger;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Modules\Compliance\Support\ThresholdReport;
use App\Support\Locale;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Chiffre d'affaires, seuils et estimation d'une entreprise (données privées : 404 pour tout
 * autre utilisateur). Montants en centimes, entiers, avec la devise du pays.
 */
class RevenueController extends Controller
{
    public function __construct(private Entitlements $entitlements) {}

    public function index(Request $request, string $company): JsonResponse
    {
        $model = CompanyController::find($company, 'view');
        $year = self::year($request, $model->country);
        $ledger = RevenueLedger::for($model, $year);

        return response()->json([
            'data' => [
                'year' => $year,
                'currency' => RuleCatalog::currency($model->country),
                'months' => $ledger->months(),
                'by_activity' => $ledger->byActivity(),
                'total' => $ledger->total(),
            ],
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    public function save(SaveRevenueRequest $request, string $company, string $period, ManageRevenue $manageRevenue): JsonResponse
    {
        /** @var array<string, int> $lines */
        $lines = $request->validated('lines');
        $year = $manageRevenue->save($request->company(), $period, $lines);
        $ledger = RevenueLedger::for($request->company(), $year);
        $month = collect($ledger->months())->firstWhere('period', $period);

        return response()->json([
            'data' => [
                'period' => $period,
                'currency' => RuleCatalog::currency($request->company()->country),
                'lines' => $month['lines'] ?? [],
                'total' => $month['total'] ?? 0,
                'thresholds' => $this->thresholdsOf($request, $request->company(), $year),
            ],
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    public function destroy(string $company, string $period, ManageRevenue $manageRevenue): Response
    {
        $manageRevenue->delete(CompanyController::find($company, 'update'), $period);

        return response()->noContent();
    }

    public function thresholds(Request $request, string $company): JsonResponse
    {
        $model = CompanyController::find($company, 'view');
        $year = self::year($request, $model->country);

        return response()->json([
            'data' => ['year' => $year, 'thresholds' => $this->thresholdsOf($request, $model, $year)],
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    /**
     * Estimation premium (403 premium_required sans premium).
     */
    public function estimate(Request $request, string $company): JsonResponse
    {
        $model = CompanyController::find($company, 'view');
        $this->entitlements->require('estimate', $model->user_id);

        return response()->json([
            'data' => ContributionEstimate::for($model, self::year($request, $model->country), Locale::fromRequest($request)),
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    /**
     * Export CSV du chiffre d'affaires d'une année : période, type d'activité, montant en
     * centimes et en unités (calcul exact sur entiers), devise. Aucun texte libre : pas
     * d'injection de formule possible dans un tableur.
     */
    public function csv(Request $request, string $company): Response
    {
        $model = CompanyController::find($company, 'view');
        $year = self::year($request, $model->country);
        $currency = RuleCatalog::currency($model->country);
        $rows = ['period;activity_type;amount_cents;amount;currency'];

        foreach (RevenueLedger::for($model, $year)->entries as $entry) {
            $rows[] = implode(';', [
                $entry->period->format('Y-m'),
                $entry->activity_type,
                (string) $entry->amount,
                sprintf('%d.%02d', intdiv($entry->amount, 100), $entry->amount % 100),
                $currency,
            ]);
        }

        SecurityLog::record('compliance.export_downloaded', ['company' => $model->uuid, 'format' => 'csv']);

        return response(implode("\r\n", $rows)."\r\n", 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="chiffre-affaires-'.$year.'.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function thresholdsOf(Request $request, Company $company, int $year): array
    {
        $levels = $this->entitlements->thresholdLevels(ComplianceSettings::for($company->user_id));

        return ThresholdReport::for($company, $year, $levels, Locale::fromRequest($request));
    }

    /**
     * Année demandée (paramètre year, de 2000 à l'année en cours), par défaut l'année en cours.
     */
    private static function year(Request $request, string $country): int
    {
        $current = CarbonImmutable::now(RuleCatalog::timezone($country))->year;
        $validated = $request->validate(['year' => ['sometimes', 'integer', 'between:2000,'.$current]]);

        return (int) ($validated['year'] ?? $current);
    }
}
