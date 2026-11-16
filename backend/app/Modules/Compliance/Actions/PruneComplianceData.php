<?php

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Models\Deadline;
use App\Modules\Compliance\Models\Journey;
use App\Modules\Compliance\Models\RevenueEntry;
use App\Modules\Compliance\Models\Task;
use App\Modules\Compliance\Models\ThresholdAlert;
use Illuminate\Support\Facades\DB;

/**
 * Minimisation des données du suivi administratif (tâche compliance:prune, chaque jour),
 * durées dans config/security.php (retention). Ces données ont une valeur pour l'utilisateur :
 * les durées sont annoncées dans l'API (GET /me/compliance), l'export reste possible à tout
 * moment, et la purge annuelle du chiffre d'affaires est annoncée dans le récapitulatif du
 * 1er décembre.
 *
 * - échéances passées depuis compliance_deadlines_months (avec leurs tâches et rappels) ;
 * - chiffre d'affaires antérieur au 1er janvier de (année en cours − compliance_revenue_years) ;
 * - alertes de seuil plus anciennes que compliance_threshold_alerts_months ;
 * - parcours terminés et tâches libres cochées depuis compliance_journeys_months ;
 * - traces techniques des récapitulatifs envoyés (trois mois).
 */
class PruneComplianceData
{
    /**
     * @return array{deadlines: int, revenue: int, alerts: int, journeys: int, tasks: int}
     */
    public function handle(): array
    {
        $retention = fn (string $key): int => (int) config("security.retention.{$key}");
        $journeysBefore = now()->subMonths($retention('compliance_journeys_months'));

        $result = [
            'deadlines' => Deadline::query()
                ->whereDate('due_on', '<', now()->subMonths($retention('compliance_deadlines_months'))->toDateString())
                ->delete(),
            'revenue' => RevenueEntry::query()
                ->whereDate('period', '<', sprintf('%d-01-01', now()->year - $retention('compliance_revenue_years')))
                ->delete(),
            'alerts' => ThresholdAlert::query()
                ->where('reached_at', '<', now()->subMonths($retention('compliance_threshold_alerts_months')))
                ->delete(),
            'journeys' => Journey::query()
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $journeysBefore)
                ->delete(),
            'tasks' => Task::query()
                ->whereNull('deadline_id')
                ->whereNull('journey_id')
                ->whereNotNull('completed_at')
                ->where('completed_at', '<', $journeysBefore)
                ->delete(),
        ];

        DB::table('compliance_digests')->whereDate('sent_on', '<', now()->subMonths(3)->toDateString())->delete();

        return $result;
    }
}
