<?php

namespace App\Support\Security;

use App\Http\Middleware\EnsureModerator;
use App\Models\User;
use App\Support\Database\TimestampSchema;
use App\Support\Links\ExternalLink;
use App\Support\LocalHost;
use Closure;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Vérifie que la configuration chargée est sûre pour la production (commande
 * security:check-config, lancée à chaque déploiement).
 *
 * - Erreur : valeur dangereuse en production, le déploiement doit s'arrêter.
 * - Avertissement : à vérifier, sans bloquer.
 *
 * Les messages ne contiennent jamais la valeur d'un secret.
 */
class ConfigAudit
{
    /** @var Closure(): bool */
    private Closure $devDependenciesInstalled;

    /** @var Closure(string): (string|false) */
    private Closure $ini;

    /** @var Closure(): (array{explicit_defaults: bool, on_update_columns: list<string>}|null) */
    private Closure $timestampSchema;

    /** @var Closure(): (array{admins_with_two_factor: int, demo_rules: int}|null) */
    private Closure $complianceRules;

    /** @var list<ConfigFinding> */
    private array $findings = [];

    /**
     * @param  (Closure(): bool)|null  $devDependenciesInstalled  dépendances "require-dev" installées ?
     * @param  (Closure(string): (string|false))|null  $ini  lecture d'un réglage php.ini
     * @param  (Closure(): (array{explicit_defaults: bool, on_update_columns: list<string>}|null))|null  $timestampSchema  schéma MySQL/MariaDB (TimestampSchema::inspect)
     * @param  (Closure(): (array{admins_with_two_factor: int, demo_rules: int}|null))|null  $complianceRules  règles administratives en base (null : table absente)
     */
    public function __construct(?Closure $devDependenciesInstalled = null, ?Closure $ini = null, ?Closure $timestampSchema = null, ?Closure $complianceRules = null)
    {
        $this->devDependenciesInstalled = $devDependenciesInstalled
            ?? fn (): bool => collect(InstalledVersions::getAllRawData())->contains(fn (array $data): bool => $data['root']['dev']);
        $this->ini = $ini ?? fn (string $key): string|false => ini_get($key);
        $this->timestampSchema = $timestampSchema ?? fn (): ?array => TimestampSchema::inspect(DB::connection());
        $this->complianceRules = $complianceRules ?? fn (): ?array => Schema::hasTable('compliance_rules') ? [
            'admins_with_two_factor' => User::role(EnsureModerator::ADMIN_ROLE)->whereNotNull('two_factor_confirmed_at')->count(),
            // Lecture par nom de table : le noyau ne dépend d'aucun module.
            'demo_rules' => DB::table('compliance_rules')->where('is_demo', true)->count(),
        ] : null;
    }

    /**
     * @return list<ConfigFinding>
     */
    public function run(): array
    {
        $this->findings = [];

        $this->checkApplication();
        $this->checkSession();
        $this->checkSanctumAndCors();
        $this->checkLoggingAndMail();
        $this->checkInfrastructure();
        $this->checkMedia();
        $this->checkProxies();
        $this->checkLinks();
        $this->checkPhp();
        $this->checkTimestampColumns();
        $this->checkComplianceRules();

        return $this->findings;
    }

    private function checkApplication(): void
    {
        if (! app()->isProduction()) {
            $this->error('APP_ENV', 'doit valoir "production" (valeur actuelle : '.app()->environment().').');
        }

        if (config('app.debug') !== false) {
            $this->error('APP_DEBUG', 'doit valoir false : le mode debug affiche les traces et la configuration.');
        }

        if (blank(config('app.key'))) {
            $this->error('APP_KEY', 'est vide : lancer php artisan key:generate.');
        }

        $this->checkPublicHttpsUrl('APP_URL', config('app.url'));
        $this->checkPublicHttpsUrl('FRONTEND_URL', config('app.frontend_url'));

        if (config('app.seed_demo_data') === true) {
            $this->error('SEED_DEMO_DATA', 'doit être absent ou false : les comptes de démonstration ont un mot de passe connu.');
        }

        if ((int) config('hashing.bcrypt.rounds') < 12) {
            $this->error('BCRYPT_ROUNDS', 'doit valoir au moins 12.');
        }
    }

    private function checkSession(): void
    {
        if (config('session.secure') !== true) {
            $this->error('SESSION_SECURE_COOKIE', 'doit valoir true : le cookie de session ne doit circuler qu\'en HTTPS.');
        }

        if (config('session.http_only') !== true) {
            $this->error('SESSION_HTTP_ONLY', 'doit valoir true : le cookie de session ne doit pas être lisible en JavaScript.');
        }

        if (! in_array(config('session.same_site'), ['lax', 'strict'], true)) {
            $this->error('SESSION_SAME_SITE', 'doit valoir "lax" (ou "strict") : "none" ou vide expose aux requêtes intersites.');
        }

        if (config('session.encrypt') !== true) {
            $this->error('SESSION_ENCRYPT', 'doit valoir true : les sessions stockées en base doivent être chiffrées.');
        }

        if (config('session.driver') !== 'database') {
            $this->error('SESSION_DRIVER', 'doit valoir "database" (sessions supprimables à la déconnexion et au changement de mot de passe).');
        }

        $domain = (string) config('session.domain');
        if ($domain === '' || LocalHost::isLocalHost(ltrim($domain, '.'))) {
            $this->error('SESSION_DOMAIN', 'doit être le domaine commun à l\'API et au frontend (par exemple .loopartner.fr).');
        }

        if ((int) config('session.lifetime') > 480) {
            $this->warning('SESSION_LIFETIME', 'dépasse 8 heures : une session volée reste utilisable longtemps.');
        }
    }

    private function checkSanctumAndCors(): void
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $frontendHost = (string) parse_url($frontend, PHP_URL_HOST);
        $frontendPort = parse_url($frontend, PHP_URL_PORT);
        $frontendAuthority = $frontendHost.($frontendPort !== null ? ':'.$frontendPort : '');

        /** @var list<string> $stateful */
        $stateful = array_values(array_filter(array_map('trim', (array) config('sanctum.stateful'))));

        foreach ($stateful as $domain) {
            if (LocalHost::isLocalHost($domain)) {
                $this->error('SANCTUM_STATEFUL_DOMAINS', 'contient un domaine local ('.$domain.').');
            }
        }

        if ($frontendHost === '' || ! in_array($frontendAuthority, $stateful, true)) {
            $this->error('SANCTUM_STATEFUL_DOMAINS', 'doit contenir le domaine du frontend ('.($frontendAuthority !== '' ? $frontendAuthority : 'FRONTEND_URL vide').').');
        }

        /** @var list<string> $origins */
        $origins = (array) config('cors.allowed_origins');

        if ($origins !== [$frontend]) {
            $this->error('CORS', 'config/cors.php doit n\'autoriser que FRONTEND_URL (origines actuelles : '.implode(', ', $origins).').');
        }

        if ((array) config('cors.allowed_origins_patterns') !== []) {
            $this->error('CORS', 'config/cors.php ne doit contenir aucun motif d\'origine (allowed_origins_patterns).');
        }
    }

    private function checkLoggingAndMail(): void
    {
        foreach ($this->defaultLogChannels() as $channel) {
            $level = (string) (config("logging.channels.{$channel}.level") ?? 'debug');

            if ($level === 'debug') {
                $this->error('LOG_LEVEL', 'vaut "debug" pour le canal '.$channel.' : utiliser "warning" (ou "info") en production.');
            }
        }

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->error('MAIL_MAILER', 'ne doit pas valoir "log" ni "array" : les emails (et leurs liens de réinitialisation) seraient écrits dans les logs ou perdus.');
        }
    }

    private function checkInfrastructure(): void
    {
        if (config('queue.default') === 'sync') {
            $this->error('QUEUE_CONNECTION', 'ne doit pas valoir "sync" : les emails doivent partir par la file d\'attente (temps de réponse identique, pas d\'énumération des comptes).');
        }

        if (($this->devDependenciesInstalled)()) {
            $this->error('composer install --no-dev', 'les dépendances de développement (Laravel Boost, Pail…) sont installées : les retirer en production.');
        }
    }

    private function checkMedia(): void
    {
        if (config('media.disk') !== 'r2') {
            $this->error('MEDIA_DISK', 'doit valoir "r2" en production.');

            return;
        }

        foreach (['key' => 'R2_ACCESS_KEY_ID', 'secret' => 'R2_SECRET_ACCESS_KEY', 'bucket' => 'R2_BUCKET', 'endpoint' => 'R2_ENDPOINT', 'url' => 'R2_PUBLIC_URL'] as $key => $variable) {
            if (blank(config("filesystems.disks.r2.{$key}"))) {
                $this->error($variable, 'est vide.');
            }
        }

        $publicUrl = (string) config('filesystems.disks.r2.url');
        if ($publicUrl !== '' && ! str_starts_with($publicUrl, 'https://')) {
            $this->error('R2_PUBLIC_URL', 'doit commencer par https://.');
        }
    }

    private function checkProxies(): void
    {
        $proxies = array_filter(array_map('trim', explode(',', (string) config('security.trusted_proxies'))));

        if ($proxies === []) {
            $this->warning('TRUSTED_PROXIES', 'est vide : à renseigner si l\'API passe derrière Cloudflare ou un autre proxy (IP client et HTTPS mal détectés sinon).');
        }

        if (in_array('*', $proxies, true) || in_array('**', $proxies, true)) {
            $this->error('TRUSTED_PROXIES', 'ne doit pas valoir "*" : n\'importe quel client pourrait choisir son IP (X-Forwarded-For). Lister les plages du proxy.');
        }
    }

    /**
     * Domaines refusés dans les liens saisis : une entrée mal écrite ("https://exemple.fr",
     * "*.exemple.fr") ne bloquerait rien, sans aucune erreur visible.
     */
    private function checkLinks(): void
    {
        $entries = array_filter(array_map('trim', explode(',', (string) config('security.links.denied_domains'))));

        foreach ($entries as $entry) {
            if (! ExternalLink::isValidHostname($entry)) {
                $this->error('DENIED_LINK_DOMAINS', 'contient une entrée qui n\'est pas un nom de domaine ("'.mb_strimwidth($entry, 0, 60, '…').'") : écrire seulement le domaine, par exemple exemple.fr.');
            }
        }
    }

    private function checkPhp(): void
    {
        if (! filter_var(($this->ini)('zend.exception_ignore_args'), FILTER_VALIDATE_BOOLEAN)) {
            $this->warning('zend.exception_ignore_args', 'vaut Off (php.ini) : les arguments des fonctions, dont des mots de passe, peuvent apparaître dans les traces d\'exception.');
        }

        if (filter_var(($this->ini)('expose_php'), FILTER_VALIDATE_BOOLEAN)) {
            $this->warning('expose_php', 'vaut On (php.ini) : la version de PHP est annoncée dans les en-têtes.');
        }
    }

    /**
     * Sans explicit_defaults_for_timestamp, la première colonne TIMESTAMP NOT NULL d'une table
     * reçoit ON UPDATE CURRENT_TIMESTAMP : sa valeur est réécrite à chaque modification de la ligne.
     * Une base qui ne répond pas donne un avertissement, jamais un échec de la commande.
     */
    private function checkTimestampColumns(): void
    {
        try {
            $schema = ($this->timestampSchema)();
        } catch (Throwable) {
            // Le message de l'exception n'est pas repris : il peut contenir l'hôte ou l'utilisateur.
            $this->warning('Base de données', 'ne répond pas : explicit_defaults_for_timestamp et les colonnes ON UPDATE CURRENT_TIMESTAMP n\'ont pas été vérifiés. Relancer la commande une fois la base accessible.');

            return;
        }

        if ($schema === null) {
            return;
        }

        if (! $schema['explicit_defaults']) {
            $this->warning('explicit_defaults_for_timestamp', 'vaut OFF (MySQL/MariaDB) : la première colonne TIMESTAMP NOT NULL d\'une table reçoit ON UPDATE CURRENT_TIMESTAMP. Les migrations utilisent dateTime() pour les dates obligatoires (tests/Arch/MigrationsTest.php) ; ne jamais modifier le schéma à la main.');
        }

        foreach ($schema['on_update_columns'] as $column) {
            $this->error($column, 'a ON UPDATE CURRENT_TIMESTAMP : sa valeur est réécrite à chaque modification de la ligne. La passer en DATETIME par une nouvelle migration, puis vérifier les données déjà enregistrées.');
        }
    }

    /**
     * Règles administratives (module Compliance) :
     * - une règle de démonstration (valeurs fictives) en production est une erreur : la base de
     *   démonstration a été copiée, ou le seeder de démonstration a tourné ;
     * - le principe des quatre yeux désactivé alors que deux admins au moins ont la 2FA : un
     *   avertissement invite à l'activer (COMPLIANCE_REQUIRE_DISTINCT_VERIFIER).
     */
    private function checkComplianceRules(): void
    {
        try {
            $rules = ($this->complianceRules)();
        } catch (Throwable) {
            return; // Base injoignable : déjà signalé par checkTimestampColumns().
        }

        if ($rules === null) {
            return;
        }

        if ($rules['demo_rules'] > 0) {
            $this->error('compliance_rules', 'contient '.$rules['demo_rules'].' règle(s) de démonstration (valeurs fictives) : une base de démonstration ne doit jamais être copiée en production. Les supprimer avant l\'ouverture.');
        }

        if (config('compliance.require_distinct_verifier') !== true && $rules['admins_with_two_factor'] >= 2) {
            $this->warning('COMPLIANCE_REQUIRE_DISTINCT_VERIFIER', 'vaut false alors que '.$rules['admins_with_two_factor'].' admins ont la double authentification : l\'activer pour qu\'une règle administrative soit toujours vérifiée par un autre admin que son dernier auteur.');
        }
    }

    private function checkPublicHttpsUrl(string $variable, mixed $url): void
    {
        $url = (string) $url;

        if (! str_starts_with($url, 'https://')) {
            $this->error($variable, 'doit commencer par https://.');
        }

        if (LocalHost::isLocalUrl($url)) {
            $this->error($variable, 'pointe vers un hôte local.');
        }
    }

    /**
     * Canaux utilisés par le canal par défaut (les canaux d'un "stack", sinon lui-même).
     *
     * @return list<string>
     */
    private function defaultLogChannels(): array
    {
        $default = (string) config('logging.default');

        if (config("logging.channels.{$default}.driver") !== 'stack') {
            return [$default];
        }

        /** @var list<string> $channels */
        $channels = (array) config("logging.channels.{$default}.channels");

        return array_values(array_filter(array_map('trim', $channels)));
    }

    private function error(string $variable, string $message): void
    {
        $this->findings[] = new ConfigFinding(ConfigFinding::ERROR, $variable, $message);
    }

    private function warning(string $variable, string $message): void
    {
        $this->findings[] = new ConfigFinding(ConfigFinding::WARNING, $variable, $message);
    }
}
