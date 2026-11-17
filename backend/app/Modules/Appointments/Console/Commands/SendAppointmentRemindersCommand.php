<?php

namespace App\Modules\Appointments\Console\Commands;

use App\Modules\Appointments\Actions\SendAppointmentReminders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Envoie les rappels des rendez-vous confirmés, sans doublon. Planifiée toutes les 5 minutes
 * dans routes/console.php.
 */
#[Signature('appointments:send-reminders')]
#[Description('Envoie les rappels des rendez-vous confirmés (24 h avant par défaut), sans doublon')]
class SendAppointmentRemindersCommand extends Command
{
    public function handle(SendAppointmentReminders $sendReminders): int
    {
        $this->info(sprintf('%d rappel(s) envoyé(s).', $sendReminders->handle()));

        return self::SUCCESS;
    }
}
