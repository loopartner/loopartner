<?php

namespace App\Modules\Events\Console\Commands;

use App\Modules\Events\Actions\PruneEventData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purge les données des événements arrivées au bout de leur durée de conservation
 * (config/security.php, retention). Planifiée chaque jour dans routes/console.php.
 */
#[Signature('events:prune')]
#[Description('Supprime les inscriptions, révisions, annonces et événements clos au-delà de leur durée de conservation')]
class PruneEventDataCommand extends Command
{
    public function handle(PruneEventData $pruneEventData): int
    {
        $result = $pruneEventData->handle();

        $this->info(sprintf(
            '%d inscription(s), %d révision(s), %d annonce(s) et %d événement(s) supprimé(s).',
            $result['registrations'],
            $result['revisions'],
            $result['announcements'],
            $result['events'],
        ));

        return self::SUCCESS;
    }
}
