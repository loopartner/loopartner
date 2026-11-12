<?php

namespace App\Console\Commands;

use App\Support\AdminAudit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purge le journal d'audit des décisions d'administration au-delà de sa durée de conservation
 * (config/security.php, retention.admin_audit_months). Planifiée chaque jour dans routes/console.php.
 */
#[Signature('admin-audit:prune')]
#[Description('Supprime les entrées du journal d\'audit d\'administration au-delà de leur durée de conservation')]
class PruneAdminAuditLog extends Command
{
    public function handle(): int
    {
        $this->info(sprintf('%d entrée(s) du journal d\'audit supprimée(s).', AdminAudit::prune()));

        return self::SUCCESS;
    }
}
