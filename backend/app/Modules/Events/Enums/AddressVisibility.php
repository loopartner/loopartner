<?php

namespace App\Modules\Events\Enums;

/**
 * Visibilité de l'adresse d'un événement en présentiel ou hybride.
 *
 * - public : adresse complète visible de tous ;
 * - registrants : le public ne voit que le nom du lieu, la ville et le pays ; l'adresse et le
 *   code postal sont réservés aux inscrits, à l'organisateur et aux admins pendant la modération.
 */
enum AddressVisibility: string
{
    case Public = 'public';
    case Registrants = 'registrants';
}
