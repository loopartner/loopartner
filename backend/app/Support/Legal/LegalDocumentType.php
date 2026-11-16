<?php

namespace App\Support\Legal;

/**
 * Documents légaux que tout utilisateur accepte : conditions générales d'utilisation et
 * politique de confidentialité.
 */
enum LegalDocumentType: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $type): string => $type->value, self::cases());
    }
}
