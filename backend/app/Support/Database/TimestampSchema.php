<?php

namespace App\Support\Database;

use Illuminate\Database\Connection;

/**
 * Réglages et colonnes MySQL/MariaDB liés au piège des colonnes TIMESTAMP : sans
 * explicit_defaults_for_timestamp, la première colonne TIMESTAMP NOT NULL d'une table reçoit
 * implicitement ON UPDATE CURRENT_TIMESTAMP et sa valeur est réécrite à chaque modification.
 *
 * Utilisé par security:check-config (App\Support\Security\ConfigAudit).
 */
final class TimestampSchema
{
    /** Colonne pour laquelle ON UPDATE CURRENT_TIMESTAMP reste acceptable. */
    public const ALLOWED_ON_UPDATE_COLUMN = 'updated_at';

    /**
     * Rapport sur la connexion, ou null si elle n'est ni MySQL ni MariaDB (SQLite des tests).
     * Lève une exception si la base ne répond pas.
     *
     * @return array{explicit_defaults: bool, on_update_columns: list<string>}|null
     */
    public static function inspect(Connection $connection): ?array
    {
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }

        // Sans qualificatif : valeur de session si elle existe (MySQL 8, MariaDB 10.10+), sinon
        // valeur globale (MariaDB 10.4, où @@SESSION.… lève l'erreur 1238). C'est celle des migrations.
        $explicitDefaults = $connection->selectOne('SELECT @@explicit_defaults_for_timestamp AS value');

        // MariaDB : "on update current_timestamp()" ; MySQL 8 : "DEFAULT_GENERATED on update CURRENT_TIMESTAMP".
        $columns = $connection->select(
            'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name FROM information_schema.COLUMNS '
            ."WHERE TABLE_SCHEMA = DATABASE() AND LOWER(EXTRA) LIKE '%on update%' AND COLUMN_NAME <> ? "
            .'ORDER BY TABLE_NAME, ORDINAL_POSITION',
            [self::ALLOWED_ON_UPDATE_COLUMN],
        );

        return [
            'explicit_defaults' => filter_var($explicitDefaults->value ?? false, FILTER_VALIDATE_BOOLEAN),
            'on_update_columns' => array_values(array_map(
                fn (object $row): string => $row->table_name.'.'.$row->column_name,
                $columns,
            )),
        ];
    }
}
