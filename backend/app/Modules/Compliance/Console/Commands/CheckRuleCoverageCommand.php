<?php

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Actions\CheckRuleCoverage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Mise à jour annuelle des règles : prévient les admins des règles à vérifier pour l'année
 * suivante (du 1er octobre au 30 novembre, au plus une fois par semaine). Planifiée chaque jour
 * dans routes/console.php.
 */
#[Signature('compliance:check-rule-coverage')]
#[Description('Signale aux admins les règles administratives à vérifier pour l\'année suivante')]
class CheckRuleCoverageCommand extends Command
{
    public function handle(CheckRuleCoverage $checkRuleCoverage): int
    {
        $this->info(sprintf('%d admin(s) prévenu(s).', $checkRuleCoverage->handle()));

        return self::SUCCESS;
    }
}
