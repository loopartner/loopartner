<?php

namespace App\Modules\Compliance\Enums;

/**
 * Regroupement des taux dans l'estimation (cotisations sociales, impôt, formation…).
 */
enum RateCategory: string
{
    case Social = 'social';
    case Tax = 'tax';
    case Training = 'training';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
