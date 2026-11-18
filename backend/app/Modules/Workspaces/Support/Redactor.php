<?php

namespace App\Modules\Workspaces\Support;

/**
 * Expurgation du texte saisi avant tout envoi à l'IA (décision 64) : adresses email, liens et
 * noms de domaine, IBAN, numéros à 9 ou 14 chiffres (SIREN, SIRET) et numéros de téléphone
 * sont remplacés par un marqueur. L'utilisateur voit le résultat dans la prévisualisation.
 *
 * Ce filtre retire les identifiants courants ; il ne détecte pas une donnée sensible écrite en
 * toutes lettres (santé, opinions) : l'avertissement près du champ la prévient (J5).
 */
final class Redactor
{
    /**
     * Motifs appliqués dans l'ordre (les plus précis d'abord).
     *
     * @var array<string, string>
     */
    private const PATTERNS = [
        // IBAN (2 lettres, 2 chiffres, puis groupes alphanumériques, espaces permis).
        '/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]{4}){2,7}(?:[ ]?[A-Z0-9]{1,4})?\b/u' => '[iban]',
        '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.\p{L}{2,}/u' => '[email]',
        '/\b(?:https?:\/\/|www\.)\S+/iu' => '[lien]',
        // Nom de domaine nu (exemple.fr, boutique.example.com).
        '/\b(?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+(?:com|fr|net|org|eu|io|be|ch|ca|de|es|it|uk|info|biz|shop|store|app|dev|co|me|pro|online|site|xyz)\b(?:\/\S*)?/iu' => '[lien]',
        // SIRET (14 chiffres) puis SIREN (9 chiffres), espaces permis entre les groupes.
        '/\b\d{3}[ .]?\d{3}[ .]?\d{3}[ .]?\d{5}\b/u' => '[numéro]',
        '/\b\d{3}[ .]?\d{3}[ .]?\d{3}\b/u' => '[numéro]',
        // Téléphone : international (+33…, 0033…) ou national français (0X XX XX XX XX).
        '/(?:\+|\b00)\d{1,3}[ .\-]?(?:\(0\)[ .\-]?)?\d(?:[ .\-]?\d){6,12}\b/u' => '[téléphone]',
        '/\b0[1-9](?:[ .\-]?\d{2}){4}\b/u' => '[téléphone]',
    ];

    /**
     * @return array{0: string, 1: int} texte expurgé et nombre de remplacements
     */
    public static function redact(?string $text): array
    {
        if ($text === null || $text === '') {
            return [(string) $text, 0];
        }

        $total = 0;

        foreach (self::PATTERNS as $pattern => $marker) {
            $text = (string) preg_replace($pattern, $marker, $text, -1, $count);
            $total += $count;
        }

        return [$text, $total];
    }
}
