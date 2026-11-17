<?php

namespace App\Modules\Marketplace\Console\Commands;

use App\Modules\Marketplace\Actions\PruneMarketplaceData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purge les devis arrivés au bout de leur durée de conservation (config/security.php,
 * retention). Planifiée chaque jour dans routes/console.php.
 */
#[Signature('marketplace:prune')]
#[Description('Supprime les brouillons et les devis clos au-delà de leur durée de conservation')]
class PruneMarketplaceDataCommand extends Command
{
    public function handle(PruneMarketplaceData $pruneMarketplaceData): int
    {
        $result = $pruneMarketplaceData->handle();

        $this->info(sprintf('%d brouillon(s), %d devis clos supprimé(s).', $result['drafts'], $result['closed']));

        return self::SUCCESS;
    }
}
