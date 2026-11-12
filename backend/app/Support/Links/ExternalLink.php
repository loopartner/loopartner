<?php

namespace App\Support\Links;

/**
 * Analyse des liens externes saisis par les utilisateurs (événements, articles…).
 *
 * Un lien accepté :
 * - commence par https:// ;
 * - ne contient ni identifiants (user:pass@), ni espace, ni caractère de contrôle ;
 * - n'utilise pas d'autre port que 443 ;
 * - désigne un nom de domaine public : ni adresse IP (y compris les formes décimale, octale ou
 *   hexadécimale comme https://2130706433 ou https://0x7f.1), ni hôte local ou réservé
 *   (localhost, .local, .internal, .test…), ni nom sans point ;
 * - n'est ni un raccourcisseur de liens ni un domaine refusé (config/security.php, links),
 *   sous-domaines compris.
 *
 * Un nom de domaine internationalisé doit être écrit sous sa forme punycode (xn--…) : les
 * caractères non ASCII permettent les homographes (« аpple.com » avec un « а » cyrillique).
 * La forme punycode est acceptée mais signalée à la modération (isPunycode()).
 *
 * Le serveur n'ouvre jamais ces liens et ne résout pas leur nom : aucune requête sortante (SSRF).
 */
final class ExternalLink
{
    /**
     * Suffixes réservés aux réseaux locaux, aux tests ou à la documentation (RFC 2606, 6761, 6762…).
     *
     * @var list<string>
     */
    private const RESERVED_SUFFIXES = [
        'localhost', 'local', 'localdomain', 'internal', 'intranet', 'private', 'corp', 'home',
        'lan', 'home.arpa', 'arpa', 'test', 'example', 'invalid', 'onion',
    ];

    /**
     * URL écrites avec un schéma (http, https, ftp…) ou commençant par « www. ».
     */
    private const URL_PATTERN = '~\b(?:[a-z][a-z0-9+.\-]*://|www\.)[^\s<>"\'«»()\[\]{}]+~iu';

    /**
     * Noms de domaine écrits sans schéma (« exemple.fr », « inscription.exemple.com/page »).
     * L'extension finale contient au moins deux lettres : « 1.5 » ou « M. Dupont » ne sont pas
     * des domaines.
     */
    private const DOMAIN_PATTERN = '~(?<![\w.\-])((?:[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,63}|xn--[a-z0-9\-]{1,59}))(?![\w\-]|\.[a-z0-9])~i';

    /**
     * Problème du lien, ou null s'il est acceptable.
     */
    public static function problem(string $url): ?string
    {
        // Espaces, caractères de contrôle ou de format (U+200B, contrôles de direction…), UTF-8 invalide.
        if (preg_match('/[\s\p{Cc}\p{Cf}\p{Z}]/u', $url) !== 0) {
            return 'Le lien ne doit contenir ni espace ni caractère invisible.';
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return 'Le lien n\'est pas une adresse web valide.';
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return 'Le lien doit commencer par https://.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'Le lien ne doit pas contenir d\'identifiants.';
        }

        if (isset($parts['port']) && $parts['port'] !== 443) {
            return 'Le lien ne doit pas indiquer de port.';
        }

        return self::hostProblem($parts['host']);
    }

    /**
     * Problème d'un nom d'hôte, ou null s'il est acceptable.
     */
    public static function hostProblem(string $host): ?string
    {
        $host = self::normalizeHost($host);

        if ($host === '') {
            return 'Le lien n\'est pas une adresse web valide.';
        }

        if (preg_match('/[^\x20-\x7E]/', $host) === 1) {
            return 'Écrivez le nom de domaine sous sa forme punycode (xn--…).';
        }

        if (self::isIpAddress($host)) {
            return 'Le lien doit utiliser un nom de domaine, pas une adresse IP.';
        }

        if (! str_contains($host, '.') || self::matchesAny($host, self::RESERVED_SUFFIXES)) {
            return 'Le lien doit pointer vers un site public.';
        }

        if (! self::isValidHostname($host)) {
            return 'Le lien n\'est pas une adresse web valide.';
        }

        if (self::matchesAny($host, self::shorteners())) {
            return 'Les raccourcisseurs de liens ne sont pas acceptés : indiquez l\'adresse complète.';
        }

        if (self::matchesAny($host, self::deniedDomains())) {
            return 'Ce site n\'est pas accepté sur Loopartner.';
        }

        return null;
    }

    /**
     * Nom d'hôte d'un lien, en minuscules et sans point final (null si le lien est invalide).
     */
    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? self::normalizeHost($host) : null;
    }

    /**
     * Liens et noms de domaine trouvés dans un texte libre, dans leur ordre d'apparition et
     * sans doublon. Chaque élément est la chaîne telle qu'écrite ; host() ou textHost() en
     * donne le nom d'hôte.
     *
     * @return list<string>
     */
    public static function extract(string $text): array
    {
        $found = [];

        preg_match_all(self::URL_PATTERN, $text, $urls, PREG_OFFSET_CAPTURE);

        // Les URL complètes sont retirées du texte avant de chercher les domaines seuls.
        $remaining = $text;
        foreach ($urls[0] as [$url, $offset]) {
            $url = rtrim($url, '.,;:!?');
            $found[$offset] = $url;
            $remaining = substr_replace($remaining, str_repeat(' ', strlen($url)), $offset, strlen($url));
        }

        preg_match_all(self::DOMAIN_PATTERN, $remaining, $domains, PREG_OFFSET_CAPTURE);
        foreach ($domains[1] as [$domain, $offset]) {
            $found[$offset] = $domain;
        }

        ksort($found);

        return array_values(array_unique($found));
    }

    /**
     * Nom d'hôte d'un élément renvoyé par extract() (URL avec ou sans schéma, ou domaine seul).
     */
    public static function textHost(string $link): ?string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $link) !== 1) {
            $link = 'https://'.$link;
        }

        return self::host($link);
    }

    /**
     * Problème d'un élément renvoyé par extract(), ou null. Une URL écrite avec un schéma suit
     * les règles complètes (https…) ; un domaine seul, celles du nom d'hôte.
     */
    public static function textLinkProblem(string $link): ?string
    {
        if (preg_match('~^[a-z][a-z0-9+.\-]*://~i', $link) === 1) {
            return self::problem($link);
        }

        $host = self::textHost($link);

        return $host === null ? 'Le lien n\'est pas une adresse web valide.' : self::hostProblem($host);
    }

    /**
     * Nom de domaine internationalisé (une étiquette au moins commence par xn--) : signalé à la
     * modération, car il peut imiter un domaine connu.
     */
    public static function isPunycode(string $host): bool
    {
        return preg_match('/(^|\.)xn--/i', $host) === 1;
    }

    /**
     * Le nom d'hôte est-il ce domaine ou l'un de ses sous-domaines ?
     */
    public static function matchesDomain(string $host, string $domain): bool
    {
        $host = self::normalizeHost($host);
        $domain = self::normalizeHost($domain);

        return $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
    }

    /**
     * Domaines refusés de la configuration (DENIED_LINK_DOMAINS).
     *
     * @return list<string>
     */
    public static function deniedDomains(): array
    {
        return array_values(array_filter(array_map(
            fn (string $domain): string => self::normalizeHost($domain),
            explode(',', (string) config('security.links.denied_domains')),
        )));
    }

    /**
     * Le nom est-il un nom de domaine syntaxiquement valide (pour la configuration) ?
     */
    public static function isValidHostname(string $host): bool
    {
        $host = self::normalizeHost($host);

        if ($host === '' || strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        $labels = explode('.', $host);

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9](?:[a-z0-9\-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        // L'extension finale n'est jamais numérique : « 1.2.3.4 » ou « 0x7f.1 » sont des adresses IP.
        return preg_match('/^(?:[a-z]{2,63}|xn--[a-z0-9\-]{1,59})$/', end($labels)) === 1;
    }

    /**
     * @return list<string>
     */
    private static function shorteners(): array
    {
        /** @var list<string> $shorteners */
        $shorteners = (array) config('security.links.shorteners', []);

        return $shorteners;
    }

    /**
     * @param  list<string>  $domains
     */
    private static function matchesAny(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            if (self::matchesDomain($host, $domain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adresse IP sous toutes ses formes : IPv6 entre crochets, IPv4 classique, ou formes que les
     * navigateurs interprètent encore comme une IPv4 (entier décimal, octal, hexadécimal, ou
     * moins de quatre nombres : « 127.1 »).
     */
    private static function isIpAddress(string $host): bool
    {
        if (str_starts_with($host, '[') || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match('/^(?:0x[0-9a-f]*|\d+)(?:\.(?:0x[0-9a-f]*|\d+)){0,3}$/i', $host) === 1;
    }

    private static function normalizeHost(string $host): string
    {
        return rtrim(strtolower(trim($host)), '.');
    }
}
