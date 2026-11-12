<?php

namespace App\Modules\Events\Console\Commands;

use App\Modules\Events\Actions\NotifyModerators;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prévient les admins des éléments en attente de validation, au plus une fois par période.
 * Planifiée toutes les 5 minutes dans routes/console.php.
 */
#[Signature('events:notify-moderators')]
#[Description('Envoie aux admins le récapitulatif des événements, révisions et annonces en attente de validation')]
class NotifyModeratorsCommand extends Command
{
    public function handle(NotifyModerators $notifyModerators): int
    {
        $this->info(sprintf('%d admin(s) prévenu(s).', $notifyModerators->handle()));

        return self::SUCCESS;
    }
}
