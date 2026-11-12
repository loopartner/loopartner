<?php

namespace App\Modules\Accounts\Enums;

/**
 * Origine d'une période premium : attribution manuelle par un admin (aujourd'hui), ou
 * paiement (à venir, par exemple un abonnement Stripe).
 */
enum PremiumSource: string
{
    case Manual = 'manual';
    case Payment = 'payment';
}
