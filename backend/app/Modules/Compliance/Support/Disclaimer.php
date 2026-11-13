<?php

namespace App\Modules\Compliance\Support;

use App\Support\Locale;
use Illuminate\Http\Request;

/**
 * Avertissement renvoyé avec chaque réponse du module (champ racine "disclaimer"), repris en
 * pied de chaque email et dans l'abonnement au calendrier : l'outil aide à s'organiser, il ne
 * remplace pas un expert-comptable. Le frontend l'affiche sur chaque écran du suivi
 * administratif ; "version" change quand le texte change.
 */
final class Disclaimer
{
    /**
     * @var array<string, string>
     */
    private const TEXTS = [
        'fr' => 'Loopartner est une aide à l\'organisation : les échéances, seuils et estimations sont indicatifs, établis à partir de règles vérifiées par notre équipe, et ne remplacent ni les conseils d\'un expert-comptable ni l\'information officielle de l\'administration. Vérifiez vos obligations auprès des sources officielles indiquées.',
        'en' => 'Loopartner is an organisation aid: deadlines, thresholds and estimates are indicative, based on rules verified by our team, and replace neither the advice of a chartered accountant nor official information from the authorities. Check your obligations with the official sources provided.',
    ];

    /**
     * @return array{text: string, version: int}
     */
    public static function for(string $locale): array
    {
        return [
            'text' => self::TEXTS[$locale] ?? self::TEXTS[Locale::DEFAULT],
            'version' => (int) config('compliance.disclaimer_version'),
        ];
    }

    /**
     * @return array{text: string, version: int}
     */
    public static function forRequest(Request $request): array
    {
        return self::for(Locale::fromRequest($request));
    }

    public static function text(string $locale): string
    {
        return self::for($locale)['text'];
    }
}
