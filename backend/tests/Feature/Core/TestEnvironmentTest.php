<?php

use App\Support\Database\TimestampSchema;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
| Environnement des tests : jamais de backend/.env, jamais une base qui n'est pas une base de
| test. Sur MariaDB et MySQL (intégration continue), le schéma réellement créé par les
| migrations est contrôlé comme le fait security:check-config en production.
*/

it('ne lit aucun fichier .env', function (): void {
    expect(realpath(app()->environmentPath()))->toBe(realpath(base_path('tests')))
        ->and(app()->environmentFile())->toBe(TestCase::ENVIRONMENT_FILE)
        ->and(is_file(base_path('tests/.env')))->toBeFalse();
});

it('ne définit aucune variable dans le fichier d\'environnement des tests', function (): void {
    $lines = file(app()->environmentFilePath(), FILE_IGNORE_NEW_LINES) ?: [];
    $assignments = array_filter($lines, fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#'));

    expect(is_file(app()->environmentFilePath()))->toBeTrue()
        ->and($assignments)->toBe([]);
});

it('ne reconnaît comme base de test qu\'un nom de base de test', function (string $name, bool $accepted): void {
    expect(preg_match(TestCase::TEST_DATABASE_PATTERN, $name) === 1)->toBe($accepted);
})->with([
    ['testing', true],
    ['loopartner_test', true],
    ['loopartner_testing', true],
    ['loopartner_tests', true],
    ['loopartner', false],
    ['testing_loopartner', false],
    ['contest', false],
    ['', false],
]);

it('ne crée aucune colonne ON UPDATE CURRENT_TIMESTAMP hors updated_at (MariaDB, MySQL)', function (): void {
    $report = TimestampSchema::inspect(DB::connection());

    if ($report === null) {
        $this->markTestSkipped('Contrôle propre à MySQL et MariaDB (intégration continue).');
    }

    expect($report['on_update_columns'])->toBe([]);
});
