<?php

namespace App\Modules\Moderation\Console\Commands;

use App\Modules\Moderation\Actions\PruneModerationData;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('moderation:prune')]
#[Description('Efface les données personnelles des signalements anciens et supprime les décisions, recours et signalements au-delà de leur durée de conservation')]
class PruneModerationDataCommand extends Command
{
    public function handle(PruneModerationData $pruneModerationData): int
    {
        $result = $pruneModerationData->handle();

        $this->info(sprintf(
            '%d signalement(s) purgé(s) de leurs données personnelles ; %d décision(s) et %d signalement(s) supprimé(s).',
            $result['notices_purged'],
            $result['decisions_deleted'],
            $result['notices_deleted'],
        ));

        return self::SUCCESS;
    }
}
