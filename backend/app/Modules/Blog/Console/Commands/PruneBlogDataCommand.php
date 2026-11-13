<?php

namespace App\Modules\Blog\Console\Commands;

use App\Modules\Blog\Actions\PruneBlogData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Purge les données du Blog arrivées au bout de leur durée de conservation
 * (config/security.php, retention). Planifiée chaque jour dans routes/console.php.
 */
#[Signature('blog:prune')]
#[Description('Supprime les révisions décidées, brouillons abandonnés, articles refusés ou dépubliés et slugs retirés au-delà de leur durée de conservation')]
class PruneBlogDataCommand extends Command
{
    public function handle(PruneBlogData $pruneBlogData): int
    {
        $result = $pruneBlogData->handle();

        $this->info(sprintf(
            '%d révision(s), %d brouillon(s), %d article(s) refusé(s) ou dépublié(s) et %d slug(s) retiré(s) supprimé(s).',
            $result['revisions'],
            $result['drafts'],
            $result['closed'],
            $result['slugs'],
        ));

        return self::SUCCESS;
    }
}
