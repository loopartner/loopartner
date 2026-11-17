<?php

namespace App\Modules\Marketplace\Console\Commands;

use App\Modules\Marketplace\Actions\ExpireQuotes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fait expirer les devis envoyés dont la validité est dépassée. Planifiée chaque heure dans
 * routes/console.php.
 */
#[Signature('marketplace:expire-quotes')]
#[Description('Passe au statut expiré les devis envoyés dont la validité est dépassée')]
class ExpireQuotesCommand extends Command
{
    public function handle(ExpireQuotes $expireQuotes): int
    {
        $this->info(sprintf('%d devis expiré(s).', $expireQuotes->handle()));

        return self::SUCCESS;
    }
}
