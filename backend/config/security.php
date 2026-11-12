<?php

/*
|--------------------------------------------------------------------------
| Sécurité
|--------------------------------------------------------------------------
|
| Réglages de sécurité du projet, ajustables sans toucher au code. Les
| limiteurs nommés sont déclarés dans AppServiceProvider à partir de ces
| valeurs. Au-delà d'une limite, l'API répond 429 avec un en-tête Retry-After.
|
*/

return [

    // Proxys de confiance (App\Http\Middleware\TrustConfiguredProxies) : adresses IP ou plages
    // CIDR séparées par des virgules. Vide : X-Forwarded-* est ignoré. À régler au déploiement
    // (plages de Cloudflare si l'API passe par Cloudflare) ; security:check-config avertit s'il est vide.
    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

    'rate_limits' => [

        // Connexion : par couple email + IP, et par email toutes IP confondues (bourrage
        // d'identifiants réparti). Un attaquant peut au pire bloquer un compte une heure.
        'login' => [
            'per_minute' => 5,
            'per_hour_per_email' => 30,
        ],

        // Défi de double authentification, par connexion en cours (login.id en session).
        'two_factor' => [
            'per_minute' => 5,
            'per_hour' => 20,
        ],

        // Inscription, par IP : assez large pour un événement de networking sur un même Wi-Fi.
        // Par email aussi : une adresse existante reçoit un email à chaque tentative.
        'register' => [
            'per_hour_per_ip' => 30,
            'per_hour_per_email' => 5,
        ],

        // Demande de lien de réinitialisation : par email (bombardement d'une victime) et par IP.
        'forgot_password' => [
            'per_hour_per_email' => 5,
            'per_hour_per_ip' => 20,
        ],

        // Réinitialisation avec un jeton, par IP.
        'reset_password' => [
            'per_hour_per_ip' => 10,
        ],

        // Changement du mot de passe et confirmation du mot de passe, par utilisateur connecté :
        // empêche la force brute du mot de passe actuel avec une session volée.
        'password_update' => [
            'per_minute' => 5,
        ],
        'password_confirm' => [
            'per_minute' => 5,
        ],

        // Renvoi du lien de vérification et clic sur ce lien.
        'verification' => [
            'per_minute' => 3,
            'per_hour' => 10,
        ],

        // Limite générale de l'API, par utilisateur ou par IP pour les invités.
        'api' => [
            'per_minute' => 60,
        ],

        // Upload de médias (POST /media), par utilisateur.
        'media_uploads' => [
            'per_minute' => 20,
        ],

        // Suppression de compte (DELETE /me), par utilisateur.
        'account_deletion' => [
            'per_minute' => 6,
        ],

        // Consultation des projets publiés (GET /projects et GET /projects/{uuid}, comptés
        // ensemble), par prestataire : freine la collecte massive.
        'projects_browse' => [
            'per_minute' => 30,
            'per_day' => 500,
        ],

        // Demandes de mise en relation envoyées (propositions et contacts confondus), par
        // utilisateur. Un dépassement est un signe de spam : "match_requests.limit_exceeded" est
        // écrit dans le journal security. S'ajoute au plafond de demandes en attente
        // (MatchRequest::MAX_PENDING_SENT).
        'match_requests' => [
            'per_hour' => 10,
            'per_day' => 20,
        ],

        // Messages envoyés dans la messagerie, par utilisateur, toutes conversations confondues.
        'messages' => [
            'per_minute' => 10,
            'per_day' => 300,
        ],

        // Signalements de messages ou de demandes, par utilisateur.
        'reports' => [
            'per_day' => 10,
        ],

        // Blocages et déblocages d'utilisateurs, par utilisateur.
        'blocks' => [
            'per_day' => 20,
        ],

    ],

    /*
    | Durées de conservation des données de la mise en relation, en mois (purge quotidienne
    | "matching:prune"). À reprendre dans la politique de confidentialité (voir SECURITY.md).
    */
    'retention' => [

        // Conversation dont un participant a supprimé son compte : conservée pour l'autre
        // participant, puis supprimée avec tous ses messages.
        'orphaned_conversations_months' => 12,

        // Demandes refusées, retirées ou expirées (sans conversation) : supprimées.
        'closed_match_requests_months' => 12,

        // Signalements : copie du contenu signalé et commentaire effacés, signalements clos
        // supprimés.
        'abuse_reports_months' => 12,

        // Journal d'audit des décisions d'administration (table admin_audit_logs) : supprimé
        // après cette durée (admin-audit:prune).
        'admin_audit_months' => 24,

    ],

    /*
    | Liens externes saisis par les utilisateurs (règles App\Rules\SafeExternalUrl et
    | SafeLinksInText, App\Support\Links\ExternalLink). Seul https est accepté ; les adresses IP,
    | les hôtes locaux, les raccourcisseurs et les domaines refusés sont rejetés (sous-domaines
    | compris). Le serveur n'ouvre jamais ces liens : aucune requête sortante.
    */
    'links' => [

        // Raccourcisseurs de liens : ils masquent la destination réelle à la modération.
        'shorteners' => [
            'bit.ly', 'bitly.com', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'is.gd', 'buff.ly',
            'cutt.ly', 'rebrand.ly', 'shorturl.at', 'tiny.cc', 'rb.gy', 't.ly', 's.id', 'lnkd.in',
            'v.gd', 'bl.ink', 'shorte.st', 'adf.ly', 'qrco.de', 'urlz.fr', 'tinu.be', 'short.io',
            'linktr.ee', 'x.co', 'po.st', 'soo.gd', 'clck.ru', 'gg.gg', 'trib.al', 'dlvr.it',
        ],

        // Domaines refusés (hameçonnage, spam…), séparés par des virgules, sous-domaines compris.
        // security:check-config vérifie que chaque entrée est un nom de domaine valide.
        'denied_domains' => env('DENIED_LINK_DOMAINS', ''),

    ],

    'scraping' => [

        // Au-delà de ce nombre de consultations de projets dans la journée (UTC), un
        // avertissement "projects.scraping_suspected" est écrit une fois dans le journal security.
        'projects_daily_threshold' => 300,

    ],

];
