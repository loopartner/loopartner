<?php

namespace App\Modules\Appointments\Enums;

/**
 * Où un type de rendez-vous est réservable : dans une mise en relation acceptée seulement
 * (relation), ou aussi sur la page de réservation publique du prestataire Premium (public).
 */
enum TypeVisibility: string
{
    case Relation = 'relation';
    case Public = 'public';
}
