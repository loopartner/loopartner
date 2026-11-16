<?php

use App\Models\LegalDocument;
use App\Models\User;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Support\Database\TimestampSchema;
use App\Support\Security\ConfigAudit;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;

/*
| security:check-config, lancée à chaque déploiement : échoue (code 1) dès qu'une valeur est
| dangereuse en production. Les avertissements ne bloquent pas le déploiement.
*/

// Base de production saine : CGU et politique de confidentialité en vigueur.
beforeEach(function (): void {
    legalDocumentsInForce();
});

/**
 * Configuration de production sûre. Chaque test en dégrade une seule valeur.
 *
 * @param  array<string, string|false>  $ini  valeurs php.ini simulées
 */
function safeProductionConfig(bool $devDependencies = false, array $ini = []): void
{
    app()->detectEnvironment(fn (): string => 'production');

    config([
        'app.debug' => false,
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'app.url' => 'https://api.loopartner.fr',
        'app.frontend_url' => 'https://app.loopartner.fr',
        'app.seed_demo_data' => false,
        'session.driver' => 'database',
        'session.secure' => true,
        'session.http_only' => true,
        'session.same_site' => 'lax',
        'session.encrypt' => true,
        'session.domain' => '.loopartner.fr',
        'session.lifetime' => 120,
        'sanctum.stateful' => ['app.loopartner.fr'],
        'cors.allowed_origins' => ['https://app.loopartner.fr'],
        'cors.allowed_origins_patterns' => [],
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['daily'],
        'logging.channels.daily.level' => 'warning',
        'mail.default' => 'smtp',
        'hashing.bcrypt.rounds' => 12,
        'queue.default' => 'database',
        'media.disk' => 'r2',
        'filesystems.disks.r2.key' => 'cle',
        'filesystems.disks.r2.secret' => 'secret',
        'filesystems.disks.r2.bucket' => 'loopartner',
        'filesystems.disks.r2.endpoint' => 'https://compte.r2.cloudflarestorage.com',
        'filesystems.disks.r2.url' => 'https://media.loopartner.fr',
        'security.trusted_proxies' => '173.245.48.0/20',
        'moderation.contact.users_email' => 'contact@loopartner.com',
        'moderation.contact.authorities_email' => 'legal@loopartner.com',
        ...collect(array_keys((array) config('moderation.terms_clauses')))
            ->mapWithKeys(fn (string $key): array => ["moderation.terms_clauses.{$key}.reference" => 'Article 7'])
            ->all(),
    ]);

    $ini = [...['zend.exception_ignore_args' => '1', 'expose_php' => '0'], ...$ini];

    app()->bind(ConfigAudit::class, fn (): ConfigAudit => new ConfigAudit(
        devDependenciesInstalled: fn (): bool => $devDependencies,
        ini: fn (string $key): string|false => $ini[$key] ?? false,
        timestampSchema: fn (): array => ['explicit_defaults' => true, 'on_update_columns' => []],
    ));
}

/**
 * Remplace la lecture du schéma MySQL/MariaDB (par défaut : base saine).
 */
function fakeTimestampSchema(Closure $timestampSchema): void
{
    app()->bind(ConfigAudit::class, fn (): ConfigAudit => new ConfigAudit(
        devDependenciesInstalled: fn (): bool => false,
        ini: fn (string $key): string|false => ['zend.exception_ignore_args' => '1', 'expose_php' => '0'][$key] ?? false,
        timestampSchema: $timestampSchema,
    ));
}

it('réussit avec une configuration de production sûre', function (): void {
    safeProductionConfig();

    $this->artisan('security:check-config')
        ->expectsOutputToContain('Aucun problème')
        ->assertSuccessful();
});

it('échoue sur une valeur dangereuse en production', function (Closure $degrade, string $variable): void {
    safeProductionConfig();
    $degrade();

    $this->artisan('security:check-config')
        ->expectsOutputToContain($variable)
        ->assertFailed();
})->with([
    'environnement local' => [fn () => app()->detectEnvironment(fn (): string => 'local'), 'APP_ENV'],
    'mode debug' => [fn () => config(['app.debug' => true]), 'APP_DEBUG'],
    'clé absente' => [fn () => config(['app.key' => '']), 'APP_KEY'],
    'API en HTTP' => [fn () => config(['app.url' => 'http://api.loopartner.fr']), 'APP_URL'],
    'API locale' => [fn () => config(['app.url' => 'https://localhost']), 'APP_URL'],
    'frontend en HTTP' => [fn () => config(['app.frontend_url' => 'http://app.loopartner.fr']), 'FRONTEND_URL'],
    'cookie non sécurisé' => [fn () => config(['session.secure' => null]), 'SESSION_SECURE_COOKIE'],
    'cookie lisible en JavaScript' => [fn () => config(['session.http_only' => false]), 'SESSION_HTTP_ONLY'],
    'SameSite none' => [fn () => config(['session.same_site' => 'none']), 'SESSION_SAME_SITE'],
    'SameSite absent' => [fn () => config(['session.same_site' => null]), 'SESSION_SAME_SITE'],
    'session non chiffrée' => [fn () => config(['session.encrypt' => false]), 'SESSION_ENCRYPT'],
    'sessions en fichier' => [fn () => config(['session.driver' => 'file']), 'SESSION_DRIVER'],
    'domaine de session absent' => [fn () => config(['session.domain' => null]), 'SESSION_DOMAIN'],
    'domaine de session local' => [fn () => config(['session.domain' => 'localhost']), 'SESSION_DOMAIN'],
    'Sanctum localhost' => [fn () => config(['sanctum.stateful' => ['app.loopartner.fr', 'localhost:3000']]), 'SANCTUM_STATEFUL_DOMAINS'],
    'Sanctum sans le frontend' => [fn () => config(['sanctum.stateful' => ['autre.loopartner.fr']]), 'SANCTUM_STATEFUL_DOMAINS'],
    'CORS ouvert' => [fn () => config(['cors.allowed_origins' => ['*']]), 'CORS'],
    'CORS autre origine' => [fn () => config(['cors.allowed_origins' => ['https://evil.example']]), 'CORS'],
    'CORS motif' => [fn () => config(['cors.allowed_origins_patterns' => ['#.*#']]), 'CORS'],
    'journal en debug' => [fn () => config(['logging.channels.daily.level' => 'debug']), 'LOG_LEVEL'],
    'emails dans le journal' => [fn () => config(['mail.default' => 'log']), 'MAIL_MAILER'],
    'données de démonstration' => [fn () => config(['app.seed_demo_data' => true]), 'SEED_DEMO_DATA'],
    'bcrypt trop faible' => [fn () => config(['hashing.bcrypt.rounds' => 10]), 'BCRYPT_ROUNDS'],
    'file d\'attente synchrone' => [fn () => config(['queue.default' => 'sync']), 'QUEUE_CONNECTION'],
    'médias en local' => [fn () => config(['media.disk' => 'public']), 'MEDIA_DISK'],
    'R2 incomplet' => [fn () => config(['filesystems.disks.r2.secret' => null]), 'R2_SECRET_ACCESS_KEY'],
    'URL publique R2 en HTTP' => [fn () => config(['filesystems.disks.r2.url' => 'http://media.loopartner.fr']), 'R2_PUBLIC_URL'],
    'tous les proxys de confiance' => [fn () => config(['security.trusted_proxies' => '*']), 'TRUSTED_PROXIES'],
    'domaine refusé écrit comme une URL' => [fn () => config(['security.links.denied_domains' => 'arnaque.example,https://spam.example']), 'DENIED_LINK_DOMAINS'],
    'domaine refusé avec joker' => [fn () => config(['security.links.denied_domains' => '*.spam.example']), 'DENIED_LINK_DOMAINS'],
    'dépendances de développement' => [fn () => safeProductionConfig(devDependencies: true), 'composer install --no-dev'],
]);

it('avertit sans échouer quand TRUSTED_PROXIES est vide', function (): void {
    safeProductionConfig();
    config(['security.trusted_proxies' => '']);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('TRUSTED_PROXIES')
        ->expectsOutputToContain('avertissement')
        ->assertSuccessful();
});

it('avertit sans échouer sur les réglages PHP et la durée de session', function (array $ini, Closure $degrade, string $expected): void {
    safeProductionConfig(ini: $ini);
    $degrade();

    $this->artisan('security:check-config')
        ->expectsOutputToContain($expected)
        ->assertSuccessful();
})->with([
    'arguments dans les traces' => [['zend.exception_ignore_args' => '0'], fn () => null, 'zend.exception_ignore_args'],
    'version de PHP exposée' => [['expose_php' => '1'], fn () => null, 'expose_php'],
    'session très longue' => [[], fn () => config(['session.lifetime' => 10080]), 'SESSION_LIFETIME'],
]);

it('avertit sans échouer quand explicit_defaults_for_timestamp vaut OFF', function (): void {
    safeProductionConfig();
    fakeTimestampSchema(fn (): array => ['explicit_defaults' => false, 'on_update_columns' => []]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('explicit_defaults_for_timestamp')
        ->expectsOutputToContain('avertissement')
        ->assertSuccessful();
});

it('échoue sur une colonne réelle avec ON UPDATE CURRENT_TIMESTAMP', function (): void {
    safeProductionConfig();
    fakeTimestampSchema(fn (): array => ['explicit_defaults' => true, 'on_update_columns' => ['events.starts_at', 'event_revisions.submitted_at']]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('events.starts_at')
        ->expectsOutputToContain('event_revisions.submitted_at')
        ->expectsOutputToContain('2 erreur(s)')
        ->assertFailed();
});

it('avertit sans échouer quand la base ne répond pas, sans reprendre le message de l\'exception', function (): void {
    safeProductionConfig();
    fakeTimestampSchema(fn (): never => throw new RuntimeException('SQLSTATE[HY000] [2002] connexion refusée pour u123_admin@db-secret.hostinger'));

    $this->artisan('security:check-config')
        ->expectsOutputToContain('ne répond pas')
        ->doesntExpectOutputToContain('db-secret')
        ->doesntExpectOutputToContain('u123_admin')
        ->assertSuccessful();
});

it('ne vérifie pas le schéma hors MySQL et MariaDB', function (): void {
    safeProductionConfig();
    fakeTimestampSchema(fn (): ?array => null);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('Aucun problème')
        ->assertSuccessful();
});

it('lit le schéma par défaut sans erreur sur la connexion de test (SQLite)', function (): void {
    expect(TimestampSchema::inspect(DB::connection()))->toBeNull();
});

it('n\'affiche jamais la valeur des secrets', function (): void {
    safeProductionConfig();
    config(['filesystems.disks.r2.secret' => 'secret-tres-confidentiel', 'app.debug' => true]);

    $this->artisan('security:check-config')
        ->doesntExpectOutputToContain('secret-tres-confidentiel')
        ->doesntExpectOutputToContain(base64_encode(str_repeat('k', 32)))
        ->assertFailed();
});

/**
 * Remplace la lecture des règles administratives (module Compliance).
 *
 * @param  array{admins_with_two_factor: int, demo_rules: int}|null  $rules
 */
function fakeComplianceRules(?array $rules): void
{
    app()->bind(ConfigAudit::class, fn (): ConfigAudit => new ConfigAudit(
        devDependenciesInstalled: fn (): bool => false,
        ini: fn (string $key): string|false => ['zend.exception_ignore_args' => '1', 'expose_php' => '0'][$key] ?? false,
        timestampSchema: fn (): array => ['explicit_defaults' => true, 'on_update_columns' => []],
        complianceRules: fn (): ?array => $rules,
    ));
}

it('échoue si la base de production contient des règles administratives de démonstration', function (): void {
    safeProductionConfig();
    fakeComplianceRules(['admins_with_two_factor' => 1, 'demo_rules' => 3]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('compliance_rules')
        ->assertFailed();
});

it('avertit sans échouer quand les quatre yeux sont désactivés alors que deux admins ont la 2FA', function (): void {
    safeProductionConfig();
    config(['compliance.require_distinct_verifier' => false]);
    fakeComplianceRules(['admins_with_two_factor' => 2, 'demo_rules' => 0]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('COMPLIANCE_REQUIRE_DISTINCT_VERIFIER')
        ->assertSuccessful();
});

it('ne signale rien sur les règles avec un seul admin, ou avec les quatre yeux activés', function (int $admins, bool $distinct): void {
    safeProductionConfig();
    config(['compliance.require_distinct_verifier' => $distinct]);
    fakeComplianceRules(['admins_with_two_factor' => $admins, 'demo_rules' => 0]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('Aucun problème')
        ->assertSuccessful();
})->with([
    'un seul admin' => [1, false],
    'quatre yeux activés' => [3, true],
]);

it('lit les règles administratives par défaut sur la base de test', function (): void {
    safeProductionConfig();
    app()->bind(ConfigAudit::class, fn (): ConfigAudit => new ConfigAudit(
        devDependenciesInstalled: fn (): bool => false,
        ini: fn (string $key): string|false => ['zend.exception_ignore_args' => '1', 'expose_php' => '0'][$key] ?? false,
        timestampSchema: fn (): array => ['explicit_defaults' => true, 'on_update_columns' => []],
    ));
    ComplianceRule::factory()->demo()->create();

    $this->artisan('security:check-config')
        ->expectsOutputToContain('compliance_rules')
        ->assertFailed();
});

it('échoue si la base de production contient les données de démonstration (comptes, règles, tous modules)', function (): void {
    allowDemoSeeding();
    $this->seed(DatabaseSeeder::class);

    safeProductionConfig();

    $this->artisan('security:check-config')
        ->expectsOutputToContain('compte(s) de démonstration')
        ->expectsOutputToContain('compliance_rules')
        ->assertFailed();
});

it('ne se laisse pas bloquer par une inscription non vérifiée avec une adresse de démonstration', function (): void {
    safeProductionConfig();
    User::factory()->unverified()->create(['email' => 'admin@example.com']);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('Aucun problème')
        ->assertSuccessful();
});

it('connaît l\'adresse de chaque compte de démonstration créé par le seeder', function (): void {
    expect(ConfigAudit::DEMO_ACCOUNT_EMAILS)
        ->toBe(array_map(fn (string $role): string => "{$role}@example.com", RoleSeeder::ROLES));
});

/**
 * Remplace la lecture des documents légaux.
 *
 * @param  array{in_force: list<string>, demo: int}|null  $documents
 */
function fakeLegalDocuments(?array $documents): void
{
    app()->bind(ConfigAudit::class, fn (): ConfigAudit => new ConfigAudit(
        devDependenciesInstalled: fn (): bool => false,
        ini: fn (string $key): string|false => ['zend.exception_ignore_args' => '1', 'expose_php' => '0'][$key] ?? false,
        timestampSchema: fn (): array => ['explicit_defaults' => true, 'on_update_columns' => []],
        legalDocuments: fn (): ?array => $documents,
    ));
}

it('échoue sans version en vigueur des CGU ou de la politique de confidentialité', function (array $inForce): void {
    safeProductionConfig();
    fakeLegalDocuments(['in_force' => $inForce, 'demo' => 0]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('legal_documents')
        ->assertFailed();
})->with([
    'aucun document' => [[]],
    'politique absente' => [['terms']],
]);

it('échoue si la base de production contient des documents légaux de démonstration', function (): void {
    safeProductionConfig();
    fakeLegalDocuments(['in_force' => ['privacy', 'terms'], 'demo' => 2]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('legal_documents')
        ->assertFailed();
});

it('lit les documents légaux par défaut sur la base de test (démonstration et version à venir exclues)', function (): void {
    safeProductionConfig();
    LegalDocument::query()->delete();
    LegalDocument::factory()->create(['version' => 'cgu-a-venir', 'effective_at' => now()->addDay()]);
    LegalDocument::factory()->privacy()->create(['version' => 'demo-1', 'is_demo' => true]);

    $this->artisan('security:check-config')
        ->expectsOutputToContain('legal_documents')
        ->assertFailed();
});

describe('modération (DSA)', function (): void {
    it('échoue sans point de contact valide pour les utilisateurs ou les autorités', function (string $key, ?string $value, string $variable): void {
        safeProductionConfig();
        config(["moderation.contact.{$key}" => $value]);

        $this->artisan('security:check-config')
            ->expectsOutputToContain($variable)
            ->assertFailed();
    })->with([
        'utilisateurs absent' => ['users_email', null, 'MODERATION_CONTACT_EMAIL'],
        'utilisateurs invalide' => ['users_email', 'contact-loopartner', 'MODERATION_CONTACT_EMAIL'],
        'autorités absent' => ['authorities_email', '', 'MODERATION_AUTHORITIES_EMAIL'],
    ]);

    it('échoue tant qu\'une clause des CGU citée par les décisions n\'a pas de référence', function (): void {
        safeProductionConfig();
        config(['moderation.terms_clauses.prohibited_content.reference' => null]);

        $this->artisan('security:check-config')
            ->expectsOutputToContain('moderation.terms_clauses.prohibited_content')
            ->assertFailed();
    });

    it('avertit sans échouer quand un admin peut trancher le recours contre sa propre décision', function (): void {
        safeProductionConfig();
        config(['moderation.require_distinct_appeal_reviewer' => false, 'compliance.require_distinct_verifier' => true]);
        fakeComplianceRules(['admins_with_two_factor' => 2, 'demo_rules' => 0]);

        $this->artisan('security:check-config')
            ->expectsOutputToContain('MODERATION_REQUIRE_DISTINCT_APPEAL_REVIEWER')
            ->assertSuccessful();
    });
});
