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

    ],

    'scraping' => [

        // Au-delà de ce nombre de consultations de projets dans la journée (UTC), un
        // avertissement "projects.scraping_suspected" est écrit une fois dans le journal security.
        'projects_daily_threshold' => 300,

    ],

];
