<?php

namespace App\Modules\Directory\Enums;

/**
 * Contenu de la messagerie visé par un signalement (type de contenu signalable du module
 * Moderation, voir config/moderation.php).
 */
enum ReportTarget: string
{
    case Message = 'message';
    case MatchRequest = 'match_request';
}
