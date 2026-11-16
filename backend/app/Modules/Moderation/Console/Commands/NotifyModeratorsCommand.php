<?php

namespace App\Modules\Moderation\Console\Commands;

use App\Modules\Moderation\Actions\NotifyModerators;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Récapitulatif des signalements et recours en attente envoyé aux admins avec 2FA (au plus un
 * email par période). Planifiée toutes les 5 minutes dans routes/console.php.
 */
#[Signature('moderation:notify-moderators')]
#[Description('Envoie aux admins le récapitulatif des signalements et recours en attente (sans contenu)')]
class NotifyModeratorsCommand extends Command
{
    public function handle(NotifyModerators $notifyModerators): int
    {
        $this->info(sprintf('%d admin(s) prévenu(s).', $notifyModerators->handle()));

        return self::SUCCESS;
    }
}
