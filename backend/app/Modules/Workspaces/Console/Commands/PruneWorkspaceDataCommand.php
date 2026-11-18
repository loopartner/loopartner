<?php

namespace App\Modules\Workspaces\Console\Commands;

use App\Modules\Workspaces\Actions\PruneWorkspaceData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Minimisation du module Workspaces (config/security.php, retention.copilot_suggestions_days).
 * Planifiée chaque jour dans routes/console.php.
 */
#[Signature('workspaces:prune')]
#[Description('Supprime les suggestions du copilote arrivées au bout de leur conservation')]
class PruneWorkspaceDataCommand extends Command
{
    public function handle(PruneWorkspaceData $prune): int
    {
        $result = $prune->handle();

        $this->info(sprintf('%d suggestion(s) du copilote supprimée(s).', $result['suggestions']));

        return self::SUCCESS;
    }
}
