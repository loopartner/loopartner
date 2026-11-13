<?php

namespace App\Modules\Compliance\Enums;

/**
 * Origine d'une échéance ou d'une tâche : une règle vérifiée (générée et recalculée), ou
 * l'utilisateur (jamais modifiée par un recalcul).
 */
enum DeadlineSource: string
{
    case Rule = 'rule';
    case Custom = 'custom';
}
