<?php

namespace App\Modules\Events\Console\Commands;

use App\Modules\Events\Actions\SendEventReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Rappels aux inscrits avant le début des événements, sans doublon. Planifiée toutes les
 * 15 minutes dans routes/console.php.
 */
#[Signature('events:send-reminders')]
#[Description('Envoie le rappel aux inscrits des événements qui commencent dans les prochaines 24 heures')]
class SendEventRemindersCommand extends Command
{
    public function handle(SendEventReminders $sendEventReminders): int
    {
        $this->info(sprintf('%d événement(s) rappelé(s).', $sendEventReminders->handle()));

        return self::SUCCESS;
    }
}
