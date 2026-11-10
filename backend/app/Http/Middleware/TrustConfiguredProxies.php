<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Proxys de confiance lus dans config/security.php (variable TRUSTED_PROXIES : adresses IP ou
 * plages CIDR séparées par des virgules).
 *
 * Vide par défaut : X-Forwarded-For, X-Forwarded-Host et X-Forwarded-Proto sont alors ignorés
 * et l'IP du client est l'adresse de connexion. Derrière Cloudflare ou un autre proxy, il faut
 * y déclarer ses plages d'adresses, sinon toutes les requêtes semblent venir du proxy (limites
 * de débit partagées, journal inexploitable). Ne jamais mettre "*" sur un hébergement où les
 * clients peuvent joindre le serveur directement : ils pourraient choisir leur IP.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /**
     * @return list<string>|null
     */
    protected function proxies(): ?array
    {
        $proxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('security.trusted_proxies', '')),
        )));

        return $proxies === [] ? null : $proxies;
    }
}
