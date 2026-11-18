<?php

namespace App\Modules\Workspaces\Support;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;

/**
 * Nettoyage d'un texte produit par l'IA, traité comme une donnée **non fiable** (décision 34) :
 * - un texte qui contient du HTML est écarté (null) ;
 * - les caractères invisibles ou de contrôle sont retirés ;
 * - les liens et noms de domaine sont retirés (aucun lien n'est jamais proposé par le
 *   copilote) ;
 * - les espaces sont normalisés, la longueur bornée.
 */
final class GeneratedText
{
    private const LINKS = [
        '/\b(?:https?:\/\/|www\.)\S+/iu',
        '/\b(?:javascript|data|vbscript):\S*/iu',
        '/\b(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+(?:com|fr|net|org|eu|io|be|ch|ca|de|es|it|uk|info|biz|shop|store|app|dev|co|me|pro|online|site|xyz|gouv\.fr)\b(?:\/\S*)?/iu',
    ];

    public static function clean(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value) || preg_match(NoHtml::PATTERN, $value) === 1) {
            return null;
        }

        $text = (string) preg_replace(NoInvisibleCharacters::PATTERN, '', $value);

        foreach (self::LINKS as $pattern) {
            $text = (string) preg_replace($pattern, '', $text);
        }

        $text = trim((string) preg_replace('/[ \t]+/u', ' ', $text));
        $text = (string) preg_replace('/\n{3,}/u', "\n\n", $text);

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $maxLength ? rtrim(mb_substr($text, 0, $maxLength - 1)).'…' : $text;
    }
}
