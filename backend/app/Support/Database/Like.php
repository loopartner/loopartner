<?php

namespace App\Support\Database;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Recherche LIKE sûre : les caractères % et _ saisis par l'utilisateur sont traités comme du
 * texte, pas comme des jokers.
 *
 * Le caractère d'échappement est "!", déclaré explicitement par ESCAPE : SQLite n'a pas de
 * caractère d'échappement par défaut, et "!" évite les doubles antislashs de MySQL/MariaDB.
 * La sensibilité à la casse dépend de la base : insensible sur MySQL/MariaDB (collation
 * utf8mb4 *_ci, accents compris), insensible pour l'ASCII seulement sur SQLite.
 *
 * Les noms de colonnes sont interpolés dans le SQL : ils ne doivent jamais venir de l'utilisateur.
 */
final class Like
{
    public const ESCAPE = '!';

    public static function escape(string $value): string
    {
        return str_replace(
            [self::ESCAPE, '%', '_'],
            [self::ESCAPE.self::ESCAPE, self::ESCAPE.'%', self::ESCAPE.'_'],
            $value,
        );
    }

    /**
     * Au moins une des colonnes contient le terme.
     *
     * @param  list<string>  $columns
     */
    public static function whereContains(Builder $query, array $columns, string $term): void
    {
        $pattern = '%'.self::escape($term).'%';

        $query->where(function (Builder $query) use ($columns, $pattern): void {
            foreach ($columns as $column) {
                $query->orWhereRaw($column." LIKE ? ESCAPE '".self::ESCAPE."'", [$pattern]);
            }
        });
    }

    /**
     * La colonne est égale à la valeur (sans joker), avec la sensibilité à la casse d'un LIKE.
     */
    public static function whereEquals(Builder $query, string $column, string $value): void
    {
        $query->whereRaw($column." LIKE ? ESCAPE '".self::ESCAPE."'", [self::escape($value)]);
    }
}
