<?php

namespace App\Modules\Workspaces\Enums;

/**
 * Statut d'une suggestion du copilote : en attente, appliquée au workspace ou écartée.
 */
enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Dismissed = 'dismissed';
}
