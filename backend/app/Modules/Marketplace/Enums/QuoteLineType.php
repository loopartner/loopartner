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

    public function label(string $locale): string
    {
        $labels = match ($this) {
            self::Labour => ['Main d\'œuvre', 'Labour'],
            self::Materials => ['Fournitures et matériaux', 'Materials'],
            self::Travel => ['Déplacement', 'Travel'],
            self::Equipment => ['Matériel', 'Equipment'],
            self::Disposal => ['Évacuation des déchets', 'Waste disposal'],
            self::Service => ['Prestation', 'Service'],
            self::Other => ['Autre', 'Other'],
        };

        return $locale === 'en' ? $labels[1] : $labels[0];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
