<?php

namespace App\Modules\Moderation\Enums;

/**
 * État d'un signalement : ouvert (à examiner), traité par une décision, ou classé sans
 * décision (doublon, contenu disparu, signalement manifestement infondé).
 */
enum NoticeStatus: string
{
    case Open = 'open';
    case Decided = 'decided';
    case Dismissed = 'dismissed';
}
