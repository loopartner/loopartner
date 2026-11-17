<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Motif facultatif du refus d'un devis par le client (liste fermée).
 */
enum DeclineReason: string
{
    case Price = 'price';
    case Timing = 'timing';
    case Scope = 'scope';
    case OtherProvider = 'other_provider';
    case ProjectCancelled = 'project_cancelled';
    case IncorrectDetails = 'incorrect_details';
    case Other = 'other';
}
