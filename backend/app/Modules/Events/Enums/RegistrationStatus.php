<?php

namespace App\Modules\Events\Enums;

/**
 * Statut d'une inscription : active, ou retirée par l'organisateur (sans réinscription possible).
 */
enum RegistrationStatus: string
{
    case Registered = 'registered';
    case Removed = 'removed';
}
