<?php

namespace App\Modules\Appointments\Enums;

/**
 * Créneau retenu par un rendez-vous : son créneau actuel, ou le créneau d'un report proposé.
 */
enum HoldKind: string
{
    case Current = 'current';
    case Proposed = 'proposed';
}
