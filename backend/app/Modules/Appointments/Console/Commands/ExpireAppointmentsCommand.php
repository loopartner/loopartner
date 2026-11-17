<?php

namespace App\Modules\Appointments\Console\Commands;

use App\Modules\Appointments\Actions\ExpireAppointments;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fait expirer les demandes, propositions et reports de rendez-vous restés sans réponse.
 * Planifiée toutes les 15 minutes dans routes/console.php.
 */
#[Signature('appointments:expire')]
#[Description('Fait expirer les demandes et les reports de rendez-vous restés sans réponse')]
class ExpireAppointmentsCommand extends Command
{
    public function handle(ExpireAppointments $expireAppointments): int
    {
        $result = $expireAppointments->handle();

        $this->info(sprintf('%d demande(s) et %d report(s) expiré(s).', $result['requests'], $result['changes']));

        return self::SUCCESS;
    }
}
