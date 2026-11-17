<?php

namespace App\Modules\Appointments\Enums;

/**
 * Lieu d'un type de rendez-vous :
 * - provider_place : chez le prestataire (adresse saisie sur le type) ;
 * - client_place : chez le client (adresse donnée par le client à la réservation) ;
 * - video : visioconférence (lien https saisi sur le type) ;
 * - phone : téléphone (numéro donné par le client, appelé par le prestataire).
 *
 * Le détail n'est montré à l'autre partie qu'une fois le rendez-vous confirmé.
 */
enum LocationMode: string
{
    case ProviderPlace = 'provider_place';
    case ClientPlace = 'client_place';
    case Video = 'video';
    case Phone = 'phone';

    /**
     * Le prestataire saisit le détail du lieu sur le type (adresse ou lien).
     */
    public function detailsOnType(): bool
    {
        return $this === self::ProviderPlace || $this === self::Video;
    }

    /**
     * Le client donne le détail à la réservation (adresse ou téléphone).
     */
    public function detailsFromClient(): bool
    {
        return $this === self::ClientPlace || $this === self::Phone;
    }

    /**
     * Clé du détail dans le lieu chiffré d'un rendez-vous.
     */
    public function detailKey(): string
    {
        return match ($this) {
            self::ProviderPlace, self::ClientPlace => 'address',
            self::Video => 'video_url',
            self::Phone => 'phone',
        };
    }
}
