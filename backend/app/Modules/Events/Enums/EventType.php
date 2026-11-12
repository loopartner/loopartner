<?php

namespace App\Modules\Events\Enums;

/**
 * Type d'événement (liste fermée).
 */
enum EventType: string
{
    case Workshop = 'workshop';
    case Conference = 'conference';
    case Webinar = 'webinar';
    case Networking = 'networking';
    case TradeShow = 'trade_show';
    case Other = 'other';
}
