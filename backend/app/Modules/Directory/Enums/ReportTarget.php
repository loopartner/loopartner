<?php

namespace App\Modules\Directory\Enums;

/**
 * Contenu visé par un signalement (stocké dans abuse_reports.target_type).
 */
enum ReportTarget: string
{
    case Message = 'message';
    case MatchRequest = 'match_request';
}
