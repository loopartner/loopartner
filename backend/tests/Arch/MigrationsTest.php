<?php

/*
| Dates obligatoires en DATETIME, jamais en TIMESTAMP NOT NULL.
|
| Sur MariaDB 10.4 (XAMPP) et MySQL sans explicit_defaults_for_timestamp, la première colonne
| TIMESTAMP NOT NULL d'une table reçoit implicitement DEFAULT CURRENT_TIMESTAMP ON UPDATE
| CURRENT_TIMESTAMP : sa valeur serait réécrite à chaque modification de la ligne. Les tests
| tournent sur SQLite et ne peuvent pas le voir : ce test lit donc le code des migrations.
|
| timestamp() et timestampTz() ne sont acceptés qu'avec ->nullable() ou ->useCurrent() (valeur
| par défaut explicite, sans ON UPDATE). timestamps(), softDeletes()… créent des colonnes nullables.
*/

/**
 * Colonnes déclarées avec timestamp() ou timestampTz() sans ->nullable() ni ->useCurrent().
 *
 * @return list<string>
 */
function requiredTimestampColumns(string $source): array
{
    // Retire les commentaires, puis découpe le code en instructions (une colonne par instruction,
    // même écrite sur plusieurs lignes).
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    $columns = [];

    foreach (explode(';', $code) as $statement) {
        if (preg_match('/->\s*timestamp(?:Tz)?\s*\(\s*([\'"])([^\'"]+)\1/', $statement, $match) !== 1) {
            continue;
        }

        $nullable = preg_match('/->\s*nullable\s*\(\s*(?:true\s*)?\)/i', $statement) === 1;
        $useCurrent = preg_match('/->\s*useCurrent\s*\(\s*\)/', $statement) === 1;

        if (! $nullable && ! $useCurrent) {
            $columns[] = $match[2];
        }
    }

    return $columns;
}

it('n\'utilise jamais timestamp() pour une colonne obligatoire dans les migrations', function (): void {
    $migrations = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
    expect($migrations)->not->toBeEmpty();

    $violations = [];

    foreach ($migrations as $path) {
        foreach (requiredTimestampColumns((string) file_get_contents($path)) as $column) {
            $violations[] = sprintf(
                "database/migrations/%s : la colonne \"%s\" est un timestamp() obligatoire. Écrire \$table->dateTime('%s') (valeur en UTC), "
                ."ou timestamp('%s')->nullable() / ->useCurrent(). Sur MariaDB 10.4 et MySQL sans explicit_defaults_for_timestamp, "
                .'la première colonne TIMESTAMP NOT NULL reçoit ON UPDATE CURRENT_TIMESTAMP et serait réécrite à chaque modification.',
                basename($path), $column, $column, $column,
            );
        }
    }

    expect($violations)->toBeEmpty(implode("\n", $violations));
});

it('repère les colonnes timestamp() obligatoires', function (string $source, array $expected): void {
    expect(requiredTimestampColumns("<?php\n".$source))->toBe($expected);
})->with([
    'timestamp obligatoire' => ["\$table->timestamp('starts_at');", ['starts_at']],
    'timestampTz obligatoire' => ["\$table->timestampTz('starts_at');", ['starts_at']],
    'sur plusieurs lignes' => ["\$table\n    ->timestamp(\"expires_at\")\n    ->index();", ['expires_at']],
    'nullable(false)' => ["\$table->timestamp('expires_at')->nullable(false);", ['expires_at']],
    'modification de colonne' => ["\$table->timestamp('expires_at')->change();", ['expires_at']],
    'nullable' => ["\$table->timestamp('expires_at')->nullable();", []],
    'nullable sur plusieurs lignes' => ["\$table->timestamp('decided_at')\n    ->nullable()\n    ->after('id');", []],
    'useCurrent' => ["\$table->timestamp('failed_at')->useCurrent();", []],
    'dateTime' => ["\$table->dateTime('starts_at');", []],
    'timestamps et softDeletes' => ['$table->timestamps(); $table->softDeletes();', []],
    'commentaire' => ["// \$table->timestamp('starts_at');\n\$table->dateTime('starts_at');", []],
]);
