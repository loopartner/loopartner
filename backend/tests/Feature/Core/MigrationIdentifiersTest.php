<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| Noms d'identifiants des migrations : MySQL et MariaDB refusent un nom de table, d'index, de
| clé unique ou de clé étrangère de plus de 64 caractères (erreur 1059 au migrate). SQLite ne le
| vérifie pas : ce test exécute toutes les migrations (base de test) et contrôle chaque nom.
|
| - Tables et index (uniques, simples, primaires) : noms réels lus dans le schéma, qu'ils soient
|   explicites ou générés par Laravel (<table>_<colonnes>_<type>).
| - Clés étrangères : SQLite ne garde pas leur nom ; le nom généré par Laravel
|   (<table>_<colonnes>_foreign) est recalculé, et un nom explicite écrit dans une migration
|   (->foreign([...], 'nom')) est contrôlé par lecture du code.
|
| Si un nom généré est trop long, donner un nom court explicite à l'index ou à la clé.
*/

const MAX_IDENTIFIER_LENGTH = 64;

it('ne crée aucune table, aucun index ni aucune clé de plus de 64 caractères', function (): void {
    $tooLong = [];
    $prefix = (string) config('database.connections.mariadb.prefix', '');

    foreach (Schema::getTableListing(schemaQualified: false) as $table) {
        $names = [$prefix.$table];

        foreach (Schema::getIndexes($table) as $index) {
            $names[] = $prefix.$index['name'];
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            $names[] = $foreignKey['name'] ?? strtolower(str_replace(['-', '.'], '_', $prefix.$table.'_'.implode('_', $foreignKey['columns']).'_foreign'));
        }

        foreach ($names as $name) {
            if (strlen($name) > MAX_IDENTIFIER_LENGTH) {
                $tooLong[] = "{$table} : {$name} (".strlen($name).' caractères)';
            }
        }
    }

    expect($tooLong)->toBe([]);
});

it('ne donne aucun nom explicite trop long à une clé étrangère dans les migrations', function (): void {
    $tooLong = [];

    foreach (glob(base_path('database/migrations/*.php')) ?: [] as $path) {
        preg_match_all('/->foreign\(\s*[^;]*?,\s*([\'"])([^\'"]+)\1\s*\)/s', (string) file_get_contents($path), $matches);

        foreach ($matches[2] as $name) {
            if (strlen($name) > MAX_IDENTIFIER_LENGTH) {
                $tooLong[] = basename($path)." : {$name}";
            }
        }
    }

    expect($tooLong)->toBe([]);
});

it('détecte bien un nom trop long (contrôle du garde-fou)', function (): void {
    $createTable = fn () => Schema::create('identifiant_de_test_volontairement_long', function ($table): void {
        $table->id();
        $table->unsignedBigInteger('premiere_colonne_assez_longue');
        $table->unsignedBigInteger('seconde_colonne_assez_longue');
        $table->unique(['premiere_colonne_assez_longue', 'seconde_colonne_assez_longue']);
    });

    // SQLite accepte le nom : le contrôle par lecture du schéma (premier test) doit le voir.
    if (DB::connection()->getDriverName() === 'sqlite') {
        $createTable();

        $names = collect(Schema::getIndexes('identifiant_de_test_volontairement_long'))->pluck('name');

        expect($names->contains(fn (string $name): bool => strlen($name) > MAX_IDENTIFIER_LENGTH))->toBeTrue();

        return;
    }

    // MySQL et MariaDB refusent le nom eux-mêmes (erreur 1059) : c'est l'échec que le premier
    // test anticipe sur SQLite. La table est créée avant l'index refusé, puis supprimée.
    try {
        changeSchemaInTest($createTable);
        $error = null;
    } catch (QueryException $exception) {
        $error = $exception->errorInfo[1] ?? null;
    } finally {
        changeSchemaInTest(fn () => Schema::dropIfExists('identifiant_de_test_volontairement_long'));
    }

    expect($error)->toBe(1059);
});
