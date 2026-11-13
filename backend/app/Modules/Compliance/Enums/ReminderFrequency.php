<?php

namespace App\Modules\Compliance\Enums;

/**
 * Rythme des rappels par email : un récapitulatif les jours où une échéance arrive à un délai
 * de rappel (daily), ou un récapitulatif le lundi des échéances des 14 jours suivants (weekly).
 */
enum ReminderFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';
}
