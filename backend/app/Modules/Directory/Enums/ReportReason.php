<?php

namespace App\Modules\Directory\Enums;

/**
 * Motif d'un signalement depuis la messagerie (liste fermée). Le signalement rejoint ceux de tous
 * les contenus (module Moderation) sous une catégorie, et garde ce motif.
 */
enum ReportReason: string
{
    // Longueur maximale du commentaire facultatif d'un signalement.
    public const COMMENT_MAX_LENGTH = 1000;

    case Spam = 'spam';
    case Harassment = 'harassment';
    case Scam = 'scam';
    case Inappropriate = 'inappropriate';
    // Demande de coordonnées, de paiement ou d'échange en dehors de la plateforme.
    case OffPlatform = 'off_platform';
    case Other = 'other';

    /**
     * Catégorie du signalement (config/moderation.php, categories). Même correspondance que la
     * migration qui a repris les anciens signalements.
     */
    public function category(): string
    {
        return match ($this) {
            self::Harassment => 'hate_harassment',
            self::Scam => 'scam_fraud',
            self::Other => 'other',
            self::Spam, self::Inappropriate, self::OffPlatform => 'terms',
        };
    }
}
