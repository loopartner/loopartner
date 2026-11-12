<?php

namespace App\Support\Links;

/**
 * Liens d'un contenu mis en évidence pour la modération : chaque lien et la liste des domaines,
 * avec des signaux d'alerte.
 *
 * - punycode : nom de domaine internationalisé, qui peut imiter un domaine connu ;
 * - previously_approved : domaine déjà présent dans un autre contenu validé du même auteur
 *   (un nouveau domaine mérite plus d'attention). Le module fournit ces domaines connus.
 */
final class LinkInspection
{
    /**
     * @param  list<array{field: string, link: string, host: string|null}>  $links
     * @param  list<string>  $knownHosts
     * @return array{links: list<array{field: string, link: string, host: string|null}>, domains: list<array{host: string, fields: list<string>, punycode: bool, previously_approved: bool}>}
     */
    public static function inspect(array $links, array $knownHosts): array
    {
        $domains = [];

        foreach ($links as $link) {
            if ($link['host'] === null) {
                continue;
            }

            $domains[$link['host']] ??= [
                'host' => $link['host'],
                'fields' => [],
                'punycode' => ExternalLink::isPunycode($link['host']),
                'previously_approved' => in_array($link['host'], $knownHosts, true),
            ];

            if (! in_array($link['field'], $domains[$link['host']]['fields'], true)) {
                $domains[$link['host']]['fields'][] = $link['field'];
            }
        }

        ksort($domains);

        return ['links' => $links, 'domains' => array_values($domains)];
    }
}
