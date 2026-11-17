<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Nature d'une ligne de devis (liste fermée) : sert au comparateur pour rapprocher les devis
 * (déplacement, évacuation des déchets…) sans interpréter le texte libre.
 */
enum QuoteLineType: string
{
    case Labour = 'labour';
    case Materials = 'materials';
    case Travel = 'travel';
    case Equipment = 'equipment';
    case Disposal = 'disposal';
    case Service = 'service';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
