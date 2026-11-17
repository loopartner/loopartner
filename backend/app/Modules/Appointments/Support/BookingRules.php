<?php

namespace App\Modules\Appointments\Support;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeLinksInText;

/**
 * Validation d'une réservation, d'une proposition ou d'un report.
 *
 * - starts_at : début d'un créneau affiché, en UTC ("2027-03-22T08:00:00Z").
 * - note : facultative, 500 caractères au plus, texte brut (décision 58) ; obligatoire, de 20 à
 *   500 caractères, pour une réservation de la page publique (motif de la demande de contact).
 * - address (rendez-vous chez le client) et phone (rendez-vous par téléphone) : donnés par le
 *   client, chiffrés, montrés au prestataire une fois le rendez-vous confirmé.
 */
final class BookingRules
{
    public const STARTS_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::00)?Z$/';

    public const PHONE_PATTERN = '/^\+?[0-9][0-9 .()\-]{5,24}$/';

    /**
     * @return array<string, mixed>
     */
    public static function booking(bool $publicPage = false): array
    {
        return [
            'type' => ['required', 'string', 'uuid'],
            'starts_at' => self::startsAt(),
            'note' => self::note($publicPage),
            ...self::clientLocation(),
        ];
    }

    /**
     * @return list<mixed>
     */
    public static function startsAt(): array
    {
        return ['required', 'string', 'regex:'.self::STARTS_AT_PATTERN];
    }

    /**
     * @return list<mixed>
     */
    public static function note(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            ...($required ? ['min:'.(int) config('appointments.public_note_min_length')] : []),
            'max:'.(int) config('appointments.note_max_length'),
            new NoHtml,
            new NoInvisibleCharacters,
            new SafeLinksInText,
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function clientLocation(): array
    {
        return [
            'address' => ['nullable', 'string', 'min:5', 'max:300', new NoHtml, new NoInvisibleCharacters],
            'phone' => ['nullable', 'string', 'regex:'.self::PHONE_PATTERN],
        ];
    }
}
