<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Base de données acceptée pour les tests hors SQLite (intégration continue sur MariaDB et
     * MySQL) : son nom doit annoncer une base de test (« testing », « loopartner_test »…).
     */
    public const TEST_DATABASE_PATTERN = '/(^|_)test(s|ing)?$/';

    /** Fichier d'environnement des tests, dans tests/ : volontairement vide (voir createApplication). */
    public const ENVIRONMENT_FILE = 'phpunit.env';

    /**
     * Les tests ne lisent jamais backend/.env : le dossier des variables d'environnement est
     * tests/, qui ne contient aucun fichier .env. Toute la configuration des tests vient de
     * phpunit.xml, ou de variables d'environnement explicites (intégration continue).
     *
     * Raison : backend/.env pointe vers la base locale de l'utilisateur (XAMPP), et un test
     * lancé avec RefreshDatabase la viderait ; les tests doivent aussi donner le même résultat
     * sur le poste local et en intégration continue, où aucun .env n'existe.
     *
     * Le fichier chargé est tests/phpunit.env, vide (commentaires seulement) : sans fichier à
     * lire, phpdotenv tenterait d'ouvrir tests/.env à chaque test, et PHPUnit compterait cet
     * échec de lecture comme un avertissement.
     */
    public function createApplication(): Application
    {
        /** @var Application $app */
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->useEnvironmentPath(__DIR__)->loadEnvironmentFrom(self::ENVIRONMENT_FILE);

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        $app->make(Kernel::class)->bootstrap();

        self::refuseNonTestDatabase($app);

        return $app;
    }

    /**
     * Les tests vident la base (RefreshDatabase) : hors SQLite, ils refusent de tourner sur une
     * base dont le nom n'annonce pas une base de test.
     */
    private static function refuseNonTestDatabase(Application $app): void
    {
        $connection = (string) $app['config']->get('database.default');
        $settings = (array) $app['config']->get("database.connections.{$connection}", []);

        if (($settings['driver'] ?? null) === 'sqlite') {
            return;
        }

        $database = (string) ($settings['database'] ?? '');

        if (preg_match(self::TEST_DATABASE_PATTERN, $database) !== 1) {
            throw new RuntimeException("Tests refusés : la base « {$database} » n'est pas une base de test (nom attendu : testing, <nom>_test ou <nom>_testing).");
        }
    }

    /**
     * Les requêtes de test reçoivent par défaut "Accept-Language: en-us" (valeur de Symfony
     * pour les requêtes synthétiques). On envoie plutôt l'en-tête d'un navigateur français ;
     * chaque test peut le remplacer.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9');
    }
}
