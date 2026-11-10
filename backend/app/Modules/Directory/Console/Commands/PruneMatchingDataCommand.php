<?php

namespace App\Modules\Directory\Console\Commands;

use App\Modules\Directory\Actions\PruneMatchingData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purge les données de mise en relation arrivées au bout de leur durée de conservation
 * (config/security.php, retention). Planifiée chaque jour dans routes/console.php.
 */
#[Signature('matching:prune')]
#[Description('Supprime les conversations orphelines, les demandes closes et les données des signalements au-delà de leur durée de conservation')]
class PruneMatchingDataCommand extends Command
{
    public function handle(PruneMatchingData $pruneMatchingData): int
    {
        $result = $pruneMatchingData->handle();

        $this->info(sprintf(
            '%d conversation(s) orpheline(s), %d demande(s) close(s) supprimée(s) ; %d signalement(s) purgé(s), %d supprimé(s).',
            $result['conversations'],
            $result['match_requests'],
            $result['reports_purged'],
            $result['reports_deleted'],
        ));

        return self::SUCCESS;
    }
}
