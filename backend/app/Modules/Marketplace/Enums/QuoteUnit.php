<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Unité d'une ligne de devis (arrêté du 24 janvier 2017 : « unité et quantité prévue »).
 */
enum QuoteUnit: string
{
    case Unit = 'unit';
    case Hour = 'hour';
    case Day = 'day';
    case HalfDay = 'half_day';
    case Lump = 'lump_sum';
    case SquareMetre = 'm2';
    case LinearMetre = 'm';
    case CubicMetre = 'm3';
    case Kilogram = 'kg';
    case Month = 'month';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(string $locale): string
    {
        $labels = match ($this) {
            self::Unit => ['unité', 'unit'],
            self::Hour => ['heure', 'hour'],
            self::Day => ['jour', 'day'],
            self::HalfDay => ['demi-journée', 'half day'],
            self::Lump => ['forfait', 'lump sum'],
            self::SquareMetre => ['m²', 'm²'],
            self::LinearMetre => ['mètre linéaire', 'linear metre'],
            self::CubicMetre => ['m³', 'm³'],
            self::Kilogram => ['kg', 'kg'],
            self::Month => ['mois', 'month'],
        };

        return $locale === 'en' ? $labels[1] : $labels[0];
    }
}
