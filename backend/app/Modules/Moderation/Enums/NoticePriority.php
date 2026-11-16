<?php

namespace App\Modules\Moderation\Enums;

/**
 * Priorité d'un signalement, fixée par sa catégorie (config/moderation.php) : un signalement
 * urgent déclenche une alerte immédiate aux admins et passe en tête de file.
 */
enum NoticePriority: string
{
    case Urgent = 'urgent';
    case Standard = 'standard';

    /**
     * Objectif de traitement, en heures (config/moderation.php, processing_hours).
     */
    public function processingHours(): int
    {
        return (int) config('moderation.processing_hours.'.$this->value);
    }
}
