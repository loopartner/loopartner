<?php

namespace App\Modules\Accounts\Enums;

/**
 * Types de compte qu'un visiteur peut choisir à l'inscription.
 *
 * Chaque valeur correspond au nom d'un rôle spatie. Les rôles "redacteur" et "admin"
 * n'en font volontairement pas partie : ils ne s'obtiennent jamais par l'inscription.
 */
enum AccountType: string
{
    case Particulier = 'particulier';
    case Prestataire = 'prestataire';
    case Entrepreneur = 'entrepreneur';
}
