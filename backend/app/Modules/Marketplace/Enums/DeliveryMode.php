<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Lieu de réalisation d'une offre : sur place, à distance, ou les deux.
 */
enum DeliveryMode: string
{
    case OnSite = 'on_site';
    case Remote = 'remote';
    case Both = 'both';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
