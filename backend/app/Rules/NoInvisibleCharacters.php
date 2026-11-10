<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuse les caractères invisibles et les caractères de contrôle dans un texte saisi.
 *
 * Ces caractères servent à tromper l'affichage : inversion du sens de lecture (un nom de fichier
 * "facture‮fdp.exe" s'affiche "facture‭exe.pdf"), texte caché ou mots coupés pour contourner un
 * filtre, faux doublons d'un nom. Ils sont refusés plutôt que retirés, comme le HTML (NoHtml).
 *
 * Refusés :
 * - caractères de contrôle C0 et C1 (U+0000 à U+001F, U+007F à U+009F), sauf la tabulation, le
 *   retour à la ligne (U+000A) et le retour chariot (U+000D, des navigateurs envoient CRLF) ;
 * - marque de lettre arabe U+061C et séparateur de voyelles mongol U+180E ;
 * - espace de largeur nulle U+200B, marques de direction U+200E (gauche-droite) et U+200F
 *   (droite-gauche) ;
 * - enchâssements et forçages de direction U+202A à U+202E ;
 * - U+2060 à U+2064 (gluon de mots, opérateurs invisibles), isolats de direction U+2066 à U+2069,
 *   caractères de formatage obsolètes U+206A à U+206F ;
 * - indicateur d'ordre des octets (BOM) U+FEFF.
 *
 * Une chaîne qui n'est pas de l'UTF-8 valide est aussi refusée.
 *
 * Autorisés, car nécessaires à l'écriture de certaines langues ou d'emojis :
 * - U+200C (antiliant) : persan, et plusieurs langues indiennes (devanagari, bengali…) ;
 * - U+200D (liant) : emojis composés (👨‍👩‍👧, 👩‍💻) et ligatures de plusieurs écritures indiennes.
 */
class NoInvisibleCharacters implements ValidationRule
{
    private const PATTERN = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}-\x{009F}'
        .'\x{061C}\x{180E}\x{200B}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}]/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // preg_match renvoie false sur une chaîne UTF-8 invalide : refusée elle aussi.
        if (preg_match(self::PATTERN, $value) !== 0) {
            $fail('Le champ :attribute contient des caractères invisibles ou de contrôle non autorisés.');
        }
    }
}
