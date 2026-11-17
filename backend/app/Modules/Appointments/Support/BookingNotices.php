<?php

namespace App\Modules\Appointments\Support;

/**
 * Textes d'information affichés avec les formulaires de réservation (docs/v1/appointments-rules.md,
 * section 3 ; à faire valider par le juriste) : avertissement près de la note libre
 * (décision 58) et information avant une réservation sur la page publique (décision 53).
 */
final class BookingNotices
{
    /**
     * @var array<string, array{fr: string, en: string}>
     */
    private const TEXTS = [
        'note_warning' => [
            'fr' => 'Facultatif, 500 caractères au plus. N\'indiquez pas d\'information de santé ni d\'autre donnée sensible : le prestataire lira ce message.',
            'en' => 'Optional, 500 characters at most. Do not include health information or other sensitive data: the provider will read this message.',
        ],
        'public_booking_privacy' => [
            'fr' => 'Votre motif et votre type de compte sont transmis au prestataire avec une demande de mise en relation. Votre nom ne lui est communiqué que s\'il confirme le rendez-vous.',
            'en' => 'Your message and account type are sent to the provider with a connection request. Your name is only shared if they confirm the appointment.',
        ],
    ];

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    public static function for(array $keys, string $locale): array
    {
        $texts = [];

        foreach ($keys as $key) {
            $texts[$key] = self::TEXTS[$key][$locale] ?? self::TEXTS[$key]['fr'];
        }

        return $texts;
    }
}
