<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Langues prises en charge par l'API, et langue à utiliser pour une requête.
 */
final class Locale
{
    /** @var list<string> */
    public const SUPPORTED = ['fr', 'en'];

    public const DEFAULT = 'fr';

    /**
     * Langue de la réponse : paramètre "locale" s'il est pris en charge, puis l'en-tête
     * Accept-Language ("en-US" donne "en"), puis le français. Une valeur de "locale" non prise
     * en charge est ignorée, sans erreur.
     */
    public static function fromRequest(Request $request): string
    {
        $requested = $request->query('locale');

        if (is_string($requested) && in_array($requested, self::SUPPORTED, true)) {
            return $requested;
        }

        if ($request->headers->has('Accept-Language')) {
            foreach ($request->getLanguages() as $language) {
                $primary = strtolower(explode('_', str_replace('-', '_', $language))[0]);

                if (in_array($primary, self::SUPPORTED, true)) {
                    return $primary;
                }
            }
        }

        return self::DEFAULT;
    }
}
