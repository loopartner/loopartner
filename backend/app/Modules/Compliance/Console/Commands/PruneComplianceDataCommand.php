<?php

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Actions\PruneComplianceData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purge les données du suivi administratif arrivées au bout de leur durée de conservation
 * (config/security.php, retention). Planifiée chaque jour dans routes/console.php.
 */
#[Signature('compliance:prune')]
#[Description('Supprime les échéances passées, le chiffre d\'affaires ancien, les alertes et les parcours terminés au-delà de leur durée de conservation')]
class PruneComplianceDataCommand extends Command
{
    public function handle(PruneComplianceData $pruneComplianceData): int
    {
        $result = $pruneComplianceData->handle();

        $this->info(sprintf(
            '%d échéance(s), %d saisie(s) de chiffre d\'affaires, %d alerte(s), %d parcours et %d tâche(s) supprimé(s).',
            $result['deadlines'],
            $result['revenue'],
            $result['alerts'],
            $result['journeys'],
            $result['tasks'],
        ));

        return self::SUCCESS;
    }
}
