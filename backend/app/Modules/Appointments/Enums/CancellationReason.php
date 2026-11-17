<?php

namespace App\Modules\Appointments\Enums;

/**
 * Motif d'annulation, en liste fermée (décision 55). Un message libre facultatif peut
 * l'accompagner (note signalable).
 */
enum CancellationReason: string
{
    case ScheduleConflict = 'schedule_conflict';
    case NoLongerNeeded = 'no_longer_needed';
    case Other = 'other';
}
