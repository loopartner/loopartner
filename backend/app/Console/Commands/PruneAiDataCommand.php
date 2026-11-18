<?php

namespace App\Console\Commands;

use App\Support\Ai\PruneAiData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Minimisation de la brique d'IA (config/security.php, retention.ai_usages_months). Planifiée
 * chaque jour dans routes/console.php.
 */
#[Signature('ai:prune')]
#[Description('Libère les réservations abandonnées et supprime le journal des appels à l\'IA arrivé au bout de sa conservation')]
class PruneAiDataCommand extends Command
{
    public function handle(PruneAiData $prune): int
    {
        $result = $prune->handle();

        $this->info(sprintf('%d réservation(s) abandonnée(s) libérée(s), %d appel(s) et %d compteur(s) par utilisateur supprimé(s).', $result['abandoned'], $result['usages'], $result['budgets']));

        return self::SUCCESS;
    }
}
