<?php

namespace App\Modules\Directory\Console\Commands;

use App\Modules\Directory\Actions\ExpireMatchRequests;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Fait expirer les demandes de mise en relation en attente. Planifiée chaque jour dans
 * routes/console.php, après projects:close-expired.
 */
#[Signature('match-requests:expire')]
#[Description('Fait expirer les demandes en attente depuis 30 jours et les propositions sur un projet qui n\'est plus publié')]
class ExpireMatchRequestsCommand extends Command
{
    public function handle(ExpireMatchRequests $expireMatchRequests): int
    {
        $expired = $expireMatchRequests->handle();

        $this->info("{$expired} demande(s) expirée(s).");

        return self::SUCCESS;
    }
}
