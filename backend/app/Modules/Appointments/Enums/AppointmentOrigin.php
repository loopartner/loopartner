<?php

namespace App\Modules\Appointments\Enums;

/**
 * Voie de réservation (décision 52) : dans une mise en relation acceptée, ou depuis la page de
 * réservation publique d'un prestataire Premium (avec une demande de contact).
 */
enum AppointmentOrigin: string
{
    case Relation = 'relation';
    case PublicPage = 'public_page';
}
