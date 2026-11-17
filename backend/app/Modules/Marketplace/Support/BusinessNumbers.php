<?php

namespace App\Modules\Marketplace\Support;

/**
 * Contrôles de forme des numéros d'entreprise français (aucune vérification d'existence).
 */
final class BusinessNumbers
{
    /**
     * Numéro sans espaces, points ni tirets, en majuscules (null s'il est vide).
     */
    public static function compact(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $compact = strtoupper((string) preg_replace('/[\s.\-]/u', '', $value));

        return $compact === '' ? null : $compact;
    }

    /**
     * 9 chiffres et clé de Luhn juste.
     */
    public static function isSiren(string $number): bool
    {
        return preg_match('/^\d{9}$/', $number) === 1 && self::luhn($number);
    }

    /**
     * « FR », clé de 2 chiffres, puis le SIREN ; clé = (12 + 3 × (SIREN mod 97)) mod 97.
     */
    public static function isFrenchVatNumber(string $number, string $siren): bool
    {
        if (preg_match('/^FR(\d{2})(\d{9})$/', $number, $matches) !== 1 || $matches[2] !== $siren) {
            return false;
        }

        return (int) $matches[1] === (12 + 3 * ((int) $siren % 97)) % 97;
    }

    private static function luhn(string $digits): bool
    {
        $sum = 0;

        foreach (array_reverse(str_split($digits)) as $position => $digit) {
            $value = (int) $digit * ($position % 2 === 1 ? 2 : 1);
            $sum += $value > 9 ? $value - 9 : $value;
        }

        return $sum % 10 === 0;
    }
}
