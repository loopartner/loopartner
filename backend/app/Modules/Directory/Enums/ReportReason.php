<?php

namespace App\Modules\Directory\Enums;

/**
 * Motif d'un signalement (liste fermée).
 */
enum ReportReason: string
{
    case Spam = 'spam';
    case Harassment = 'harassment';
    case Scam = 'scam';
    case Inappropriate = 'inappropriate';
    // Demande de coordonnées, de paiement ou d'échange en dehors de la plateforme.
    case OffPlatform = 'off_platform';
    case Other = 'other';
}
