<?php

namespace App\Modules\Events\Console\Commands;

use App\Modules\Events\Actions\ClosePastEvents;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Passe les événements terminés au statut "past" et refuse ceux dont la date de début est
 * passée avant leur validation. Planifiée chaque heure dans routes/console.php.
 */
#[Signature('events:close-past')]
#[Description('Passe les événements terminés au statut passé et clôt les soumissions dont la date est passée')]
class ClosePastEventsCommand extends Command
{
    public function handle(ClosePastEvents $closePastEvents): int
    {
        $result = $closePastEvents->handle();

        $this->info(sprintf('%d événement(s) passé(s), %d soumission(s) expirée(s).', $result['past'], $result['expired']));

        return self::SUCCESS;
    }
}
