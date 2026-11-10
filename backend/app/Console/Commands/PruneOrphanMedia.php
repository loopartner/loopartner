<?php

namespace App\Console\Commands;

use App\Support\Media\MediaLibrary;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Supprime les médias jamais rattachés (avatar, logo…) au-delà d'un délai, avec leurs fichiers.
 * Planifiée chaque jour dans routes/console.php.
 */
#[Signature('media:prune-orphans {--hours= : Âge minimal en heures (par défaut : media.orphan_ttl_hours)}')]
#[Description('Supprime les médias jamais rattachés et leurs fichiers')]
class PruneOrphanMedia extends Command
{
    public function handle(MediaLibrary $library): int
    {
        $option = $this->option('hours');
        $hours = is_numeric($option) ? (int) $option : (int) config('media.orphan_ttl_hours', 24);

        if ($hours < 1) {
            $this->error('Le délai doit être d\'au moins une heure.');

            return self::FAILURE;
        }

        $deleted = $library->pruneOrphans($hours);

        $this->info("{$deleted} média(s) orphelin(s) supprimé(s).");

        return self::SUCCESS;
    }
}
