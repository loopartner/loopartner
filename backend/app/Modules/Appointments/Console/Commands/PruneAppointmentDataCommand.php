<?php

namespace App\Modules\Appointments\Console\Commands;

use App\Modules\Appointments\Actions\PruneAppointmentData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Minimisation des rendez-vous (config/security.php, retention). Planifiée chaque jour dans
 * routes/console.php.
 */
#[Signature('appointments:prune')]
#[Description('Efface les lieux et notes des rendez-vous anciens et supprime ceux arrivés au bout de leur conservation')]
class PruneAppointmentDataCommand extends Command
{
    public function handle(PruneAppointmentData $pruneAppointmentData): int
    {
        $result = $pruneAppointmentData->handle();

        $this->info(sprintf(
            '%d lieu(x) ou note(s) effacé(s), %d rendez-vous clos et %d passé(s) supprimé(s), %d trace(s) de rappel, %d exception(s).',
            $result['details'],
            $result['closed'],
            $result['past'],
            $result['reminders'],
            $result['exceptions'],
        ));

        return self::SUCCESS;
    }
}
