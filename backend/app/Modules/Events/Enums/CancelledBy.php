<?php

namespace App\Modules\Events\Enums;

/**
 * Auteur de l'annulation d'un événement : son organisateur, un admin (modération, avec motif),
 * ou la plateforme (suppression du compte de l'organisateur).
 */
enum CancelledBy: string
{
    case Organizer = 'organizer';
    case Admin = 'admin';
    case System = 'system';
}
