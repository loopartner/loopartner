<?php

namespace App\Modules\Marketplace\Support;

use Carbon\CarbonInterface;

/**
 * Mise en forme des montants, quantités, taux et dates dans les PDF, sans calcul décimal : les
 * entiers sont découpés en chaîne.
 */
final class Formats
{
    /**
     * 187500 → « 1 875,00 € » (fr) ou « €1,875.00 » (en).
     */
    public static function money(int $cents, string $currency, string $locale): string
    {
        $units = intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        $symbol = $currency === 'EUR' ? '€' : $currency;

        return $locale === 'en'
            ? $symbol.number_format($units, 0, '.', ',').'.'.$fraction
            : number_format($units, 0, ',', "\u{202F}").','.$fraction."\u{00A0}".$symbol;
    }

    /**
     * Quantité en millièmes : 3500 → « 3,5 » (fr) ou « 3.5 » (en).
     */
    public static function quantity(int $milli, string $locale): string
    {
        return self::scaled($milli, 1000, $locale);
    }

    /**
     * Taux en points de base : 2000 → « 20 % », 550 → « 5,5 % ».
     */
    public static function rate(int $basisPoints, string $locale): string
    {
        return self::scaled($basisPoints, 100, $locale).($locale === 'en' ? '%' : "\u{00A0}%");
    }

    public static function date(?CarbonInterface $date, string $timezone, string $locale): string
    {
        if ($date === null) {
            return '';
        }

        return $date->copy()->setTimezone($timezone)->format($locale === 'en' ? 'Y-m-d' : 'd/m/Y');
    }

    private static function scaled(int $value, int $scale, string $locale): string
    {
        $whole = intdiv($value, $scale);
        $fraction = rtrim(str_pad((string) ($value % $scale), strlen((string) $scale) - 1, '0', STR_PAD_LEFT), '0');

        return $fraction === '' ? (string) $whole : $whole.($locale === 'en' ? '.' : ',').$fraction;
    }
}
