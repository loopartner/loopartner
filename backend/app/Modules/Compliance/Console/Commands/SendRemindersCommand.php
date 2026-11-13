<?php

namespace App\Modules\Compliance\Console\Commands;

use App\Modules\Compliance\Actions\SendDigests;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Récapitulatif quotidien du suivi administratif (rappels d'échéances, alertes de seuil) : un
 * email par utilisateur et par jour au plus. Planifiée chaque jour à 06:00 UTC.
 */
#[Signature('compliance:send-reminders')]
#[Description('Envoie le récapitulatif des échéances administratives et des alertes de seuil')]
class SendRemindersCommand extends Command
{
    public function handle(SendDigests $sendDigests): int
    {
        $this->info(sprintf('%d récapitulatif(s) envoyé(s).', $sendDigests->handle()));

        return self::SUCCESS;
    }
}
