<?php

namespace App\Support\Database;

/**
 * Document lu dans une colonne JSON : l'ordre des clés d'un objet n'y est pas garanti.
 *
 * Le type JSON natif de MySQL normalise chaque objet à l'écriture : il trie les clés (par
 * longueur, puis par octets) et ne garde pas l'ordre de l'application. MariaDB (LONGTEXT) et
 * SQLite (TEXT) le gardent. Deux documents identiques peuvent donc être deux tableaux PHP
 * différents pour `===` selon la base. Toute comparaison ou empreinte d'un document qui a pu
 * passer par une colonne JSON se fait sur sa forme canonique.
 */
final class JsonDocument
{
    /**
     * Forme canonique : clés de chaque objet triées, récursivement ; l'ordre des listes (qui
     * a un sens) et les types des valeurs sont conservés.
     */
    public static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * Égalité stricte (valeurs et types), sans tenir compte de l'ordre des clés des objets.
     */
    public static function same(mixed $first, mixed $second): bool
    {
        return self::canonical($first) === self::canonical($second);
    }
}
