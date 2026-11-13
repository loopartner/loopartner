<?php

namespace App\Modules\Blog\Console\Commands;

use App\Modules\Blog\Actions\NotifyModerators;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Prévient les modérateurs du Blog des articles et modifications en attente, au plus une fois
 * par période. Planifiée toutes les 5 minutes dans routes/console.php.
 */
#[Signature('blog:notify-moderators')]
#[Description('Envoie aux rédacteurs et admins le récapitulatif des articles et modifications en attente de validation')]
class NotifyModeratorsCommand extends Command
{
    public function handle(NotifyModerators $notifyModerators): int
    {
        $this->info(sprintf('%d modérateur(s) prévenu(s).', $notifyModerators->handle()));

        return self::SUCCESS;
    }
}
