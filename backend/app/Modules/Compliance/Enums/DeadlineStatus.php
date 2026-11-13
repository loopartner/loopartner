<?php

namespace App\Modules\Compliance\Enums;

/**
 * État d'une échéance. Une échéance faite (done) ou ignorée (skipped) n'est jamais modifiée par
 * un recalcul du calendrier.
 */
enum DeadlineStatus: string
{
    case Todo = 'todo';
    case Done = 'done';
    case Skipped = 'skipped';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
