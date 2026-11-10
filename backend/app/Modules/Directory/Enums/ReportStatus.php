<?php

namespace App\Modules\Directory\Enums;

/**
 * Statut d'un signalement. Il sera clos par la modération (back-office, à venir).
 */
enum ReportStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
