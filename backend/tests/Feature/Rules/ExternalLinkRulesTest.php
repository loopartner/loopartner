<?php

use App\Rules\LanguageCode;
use App\Rules\SafeExternalUrl;
use App\Rules\SafeLinksInText;
use App\Support\Links\ExternalLink;
use Illuminate\Support\Facades\Validator;

/*
| Règles partagées du noyau pour les liens saisis par les utilisateurs (événements, et plus tard
| le blog) et pour la langue d'un contenu.
*/

function linkPasses(mixed $value, object $rule): bool
{
    return Validator::make(['link' => $value], ['link' => [$rule]])->passes();
}

describe('SafeExternalUrl', function (): void {
    it('accepte un lien https vers un domaine public', function (string $url): void {
        expect(linkPasses($url, new SafeExternalUrl))->toBeTrue();
    })->with([
        'https://www.exemple.fr',
        'https://exemple.fr/inscription?source=loopartner#formulaire',
        'https://us02web.zoom.us/j/123456789?pwd=abc',
        'https://meet.google.com/abc-defg-hij',
        'https://EXEMPLE.FR/Page',
        'https://exemple.fr:443/page',
        'https://xn--exmple-cva.fr',
    ]);

    it('refuse un lien dangereux ou masqué', function (string $url): void {
        expect(linkPasses($url, new SafeExternalUrl))->toBeFalse();
    })->with([
        'http' => 'http://exemple.fr',
        'javascript' => 'javascript:alert(1)',
        'data' => 'data:text/html;base64,PHNjcmlwdD4=',
        'ftp' => 'ftp://exemple.fr/fichier',
        'sans schéma' => 'exemple.fr',
        'identifiants' => 'https://utilisateur:secret@exemple.fr',
        'identifiants trompeurs' => 'https://banque.fr@arnaque.example.com',
        'port' => 'https://exemple.fr:8080',
        'IPv4' => 'https://192.168.1.10/admin',
        'IPv4 publique' => 'https://8.8.8.8',
        'IPv6' => 'https://[::1]/',
        'IP décimale' => 'https://2130706433/',
        'IP hexadécimale' => 'https://0x7f000001/',
        'IP abrégée' => 'https://127.1/',
        'IP octale' => 'https://0177.0.0.1/',
        'localhost' => 'https://localhost/',
        'sous-domaine localhost' => 'https://api.localhost/',
        'mDNS' => 'https://imprimante.local/',
        'réseau interne' => 'https://intranet.internal/',
        'domaine de test' => 'https://site.test/',
        'onion' => 'https://abcdefghijklmnop.onion/',
        'nom sans point' => 'https://intranet/',
        'raccourcisseur' => 'https://bit.ly/3xYz',
        'sous-domaine de raccourcisseur' => 'https://www.tinyurl.com/abc',
        'espace' => 'https://exemple.fr/une page',
        'caractère invisible' => "https://exemple.fr/\u{200B}page",
        'homographe unicode' => 'https://аpple.com/',
        'extension numérique' => 'https://exemple.123/',
    ]);

    it('refuse les domaines de la liste configurée, sous-domaines compris', function (): void {
        config(['security.links.denied_domains' => 'arnaque-loopartner.com, SPAM.exemple-spam.org']);

        expect(linkPasses('https://arnaque-loopartner.com/offre', new SafeExternalUrl))->toBeFalse()
            ->and(linkPasses('https://www.arnaque-loopartner.com/offre', new SafeExternalUrl))->toBeFalse()
            ->and(linkPasses('https://spam.exemple-spam.org', new SafeExternalUrl))->toBeFalse()
            ->and(linkPasses('https://pasarnaque-loopartner.com/offre', new SafeExternalUrl))->toBeTrue();
    });

    it('explique la raison du refus sans répéter de secret', function (): void {
        $validator = Validator::make(['link' => 'https://moi:motdepasse@exemple.fr'], ['link' => [new SafeExternalUrl]]);

        expect($validator->errors()->first('link'))->toContain('identifiants')
            ->not->toContain('motdepasse');
    });

    it('signale les noms de domaine internationalisés', function (): void {
        expect(ExternalLink::isPunycode('xn--pple-43d.com'))->toBeTrue()
            ->and(ExternalLink::isPunycode('www.xn--pple-43d.com'))->toBeTrue()
            ->and(ExternalLink::isPunycode('exemple.fr'))->toBeFalse();
    });
});

describe('SafeLinksInText', function (): void {
    it('trouve les liens et les domaines écrits dans un texte', function (): void {
        $links = ExternalLink::extract("Inscriptions sur https://exemple.fr/inscription, infos sur www.salon.example.com.\nContact : contact@organisateur.fr ou programme.exemple.org/page.");

        expect(array_map(ExternalLink::textHost(...), $links))->toBe([
            'exemple.fr',
            'www.salon.example.com',
            'organisateur.fr',
            'programme.exemple.org',
        ]);
    });

    it('ne prend pas les nombres ni les abréviations pour des domaines', function (): void {
        expect(ExternalLink::extract('Version 1.5, 2 000 places, 18 h 30, M. Dupont et Mme Martin, prix 9.99 €.'))->toBe([]);
    });

    it('accepte un texte sans lien ou avec des liens sûrs', function (string $text): void {
        expect(linkPasses($text, new SafeLinksInText))->toBeTrue();
    })->with([
        'Atelier pratique sur la comptabilité des indépendants.',
        'Programme complet sur https://exemple.fr/programme.',
        'Rendez-vous sur salon.exemple.org pour le plan.',
    ]);

    it('refuse un texte qui contient un lien refusé', function (string $text): void {
        expect(linkPasses($text, new SafeLinksInText))->toBeFalse();
    })->with([
        'http' => 'Inscription sur http://exemple.fr/inscription.',
        'raccourcisseur' => 'Tous les détails ici : bit.ly/salon2026',
        'raccourcisseur avec schéma' => 'Tous les détails ici : https://tinyurl.com/salon',
        'IP' => 'Connectez-vous sur https://203.0.113.5/live',
        'local' => 'Démo sur https://demo.localhost/',
    ]);

    it('refuse un domaine de la liste configurée écrit sans schéma', function (): void {
        config(['security.links.denied_domains' => 'arnaque-loopartner.com']);

        expect(linkPasses('Offre spéciale sur promo.arnaque-loopartner.com !', new SafeLinksInText))->toBeFalse();
    });
});

describe('LanguageCode', function (): void {
    it('accepte un code ISO 639-1', function (string $code): void {
        expect(linkPasses($code, new LanguageCode))->toBeTrue();
    })->with(['fr', 'en', 'es', 'ar', 'zh']);

    it('refuse un code inconnu ou mal écrit', function (mixed $code): void {
        expect(linkPasses($code, new LanguageCode))->toBeFalse();
    })->with(['FR', 'fra', 'fr-FR', 'xx', 12]);
});
