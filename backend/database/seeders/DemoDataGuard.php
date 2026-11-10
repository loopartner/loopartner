<?php

namespace Database\Seeders;

use RuntimeException;

/**
 * Décide si DatabaseSeeder peut créer les données de démonstration (comptes de test au mot de
 * passe connu, dont un admin, et projets).
 *
 * Il faut à la fois :
 * - SEED_DEMO_DATA=true (config app.seed_demo_data), demandé explicitement ;
 * - APP_ENV=local ;
 * - APP_DEBUG=true ;
 * - une APP_URL locale (localhost, 127.0.0.1, ::1, *.localhost ou *.test).
 *
 * Sans SEED_DEMO_DATA, rien n'est créé. Avec SEED_DEMO_DATA mais une autre condition manquante,
 * le seed échoue bruyamment : une configuration de production erronée ne passe jamais en silence.
 */
final class DemoDataGuard
{
    /** @var list<string> */
    private const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** @var list<string> */
    private const LOCAL_SUFFIXES = ['.localhost', '.test'];

    /**
     * @throws RuntimeException si SEED_DEMO_DATA est demandé hors d'un environnement local sûr
     */
    public static function allowsSeeding(): bool
    {
        if (config('app.seed_demo_data') !== true) {
            return false;
        }

        $problems = self::problems();

        if ($problems !== []) {
            throw new RuntimeException(
                'SEED_DEMO_DATA=true refusé : '.implode(' ; ', $problems).'. Les données de démonstration ne sont créées qu\'en local.'
            );
        }

        return true;
    }

    /**
     * Conditions non remplies, en dehors de SEED_DEMO_DATA.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $problems = [];

        if (! app()->isLocal()) {
            $problems[] = 'APP_ENV doit valoir "local"';
        }

        if (config('app.debug') !== true) {
            $problems[] = 'APP_DEBUG doit valoir true';
        }

        if (! self::isLocalUrl((string) config('app.url'))) {
            $problems[] = 'APP_URL doit pointer vers un hôte local';
        }

        return $problems;
    }

    public static function isLocalUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        if (in_array($host, self::LOCAL_HOSTS, true)) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
