<?php

use App\Support\Security\ConfigAudit;

/*
| security:check-config, lancée à chaque déploiement : échoue (code 1) dès qu'une valeur est
| dangereuse en production. Les avertissements ne bloquent pas le déploiement.
*/

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
    ]);

    $ini = [...['zend.exception_ignore_args' => '1', 'expose_php' => '0'], ...$ini];

    app()->bind(ConfigAudit::class, fn (): ConfigAudit => new ConfigAudit(
        devDependenciesInstalled: fn (): bool => $devDependencies,
        ini: fn (string $key): string|false => $ini[$key] ?? false,
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

it('n\'affiche jamais la valeur des secrets', function (): void {
    safeProductionConfig();
    config(['filesystems.disks.r2.secret' => 'secret-tres-confidentiel', 'app.debug' => true]);

    $this->artisan('security:check-config')
        ->doesntExpectOutputToContain('secret-tres-confidentiel')
        ->doesntExpectOutputToContain(base64_encode(str_repeat('k', 32)))
        ->assertFailed();
});
