<?php

namespace App\Support;

/**
 * Reconnaît un hôte de développement local : localhost, 127.0.0.1, ::1, *.localhost, *.test.
 */
final class LocalHost
{
    /** @var list<string> */
    private const HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

    /** @var list<string> */
    private const SUFFIXES = ['.localhost', '.test'];

    public static function isLocalUrl(string $url): bool
    {
        return self::isLocalHost((string) parse_url($url, PHP_URL_HOST));
    }

    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        // Une entrée « hôte:port » (SANCTUM_STATEFUL_DOMAINS) : seul l'hôte compte.
        if (! str_starts_with($host, '[') && substr_count($host, ':') === 1) {
            $host = explode(':', $host)[0];
        }

        if (in_array($host, self::HOSTS, true)) {
            return true;
        }

        foreach (self::SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
