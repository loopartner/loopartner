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

        // Acceptation des documents légaux (POST /me/legal/accept), par utilisateur.
        'legal_acceptances' => [
            'per_hour' => 20,
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

        // Événements (module Events), par utilisateur.
        // Création, modification, suppression, retrait d'une soumission et annulation.
        'event_writes' => [
            'per_hour' => 60,
            'per_day' => 300,
        ],

        // Soumissions à la validation d'un admin. S'ajoute au plafond d'événements en attente
        // (config/events.php, max_pending_review_per_organizer).
        'event_submissions' => [
            'per_hour' => 5,
            'per_day' => 15,
        ],

        // Révisions d'un événement publié (création ou remplacement), comptées par l'Action.
        'event_revisions' => [
            'per_hour' => 10,
            'per_day' => 30,
        ],

        // Inscriptions et désinscriptions.
        'event_registrations' => [
            'per_hour' => 20,
            'per_day' => 60,
        ],

        // Inscrits retirés par l'organisateur.
        'event_registrant_removals' => [
            'per_day' => 20,
        ],

        // Annonces aux inscrits. S'ajoute aux limites par événement (config/events.php).
        'event_announcements' => [
            'per_day' => 3,
        ],

        // Décisions de modération (validation, refus, annulation), par admin : limite l'effet
        // d'un compte admin compromis.
        'event_moderation' => [
            'per_hour' => 60,
        ],

        // Articles (module Blog), par utilisateur.
        // Création, modification, suppression, retrait d'une soumission ou d'une révision.
        'article_writes' => [
            'per_hour' => 60,
            'per_day' => 300,
        ],

        // Soumissions à la validation (et publications directes de l'équipe). S'ajoute au quota
        // gratuit et au plafond d'articles en attente (config/blog.php).
        'article_submissions' => [
            'per_hour' => 5,
            'per_day' => 10,
        ],

        // Révisions d'un article publié (création ou remplacement), comptées par l'Action.
        'article_revisions' => [
            'per_hour' => 10,
            'per_day' => 30,
        ],

        // Décisions de modération du Blog (validation, refus, dépublication), par modérateur.
        'blog_moderation' => [
            'per_hour' => 60,
        ],

        // Attribution et retrait du statut premium par un admin (module Accounts) : limite
        // l'effet d'un compte admin compromis.
        'premium_admin' => [
            'per_hour' => 30,
        ],

        // Suivi administratif (module Compliance), par utilisateur.
        // Administration des règles (création, modification, vérification, retrait), par admin.
        'compliance_rules_admin' => [
            'per_hour' => 60,
        ],

        // Écritures courantes : réglages, échéances, tâches, parcours.
        'compliance_writes' => [
            'per_hour' => 120,
            'per_day' => 600,
        ],

        // Création et suppression d'entreprises (questionnaire de démarrage).
        'compliance_companies' => [
            'per_day' => 10,
        ],

        // Saisie du chiffre d'affaires (chaque saisie recalcule les seuils).
        'compliance_revenue' => [
            'per_hour' => 60,
        ],

        // Exports (JSON complet, CSV du chiffre d'affaires).
        'compliance_exports' => [
            'per_hour' => 10,
        ],

        // Création, remplacement et révocation de l'URL privée d'abonnement au calendrier.
        'compliance_feed_manage' => [
            'per_day' => 10,
        ],

        // Lecture de l'abonnement au calendrier (route publique à jeton) : par abonnement
        // (Google Agenda et Outlook l'interrogent quelques fois par heure au plus) et par IP.
        'compliance_feed' => [
            'per_hour_per_feed' => 30,
            'per_hour_per_ip' => 120,
        ],

        // Signalements de contenus (module Moderation, POST /moderation/notices, ouvert sans
        // compte) : par IP, et par compte ou par email du signalant (compté dans SubmitNotice).
        // Limite aussi les emails d'accusé de réception envoyés à une adresse saisie.
        'moderation_notices' => [
            'per_hour_per_ip' => 5,
            'per_day_per_ip' => 20,
            'per_day_per_user' => 10,
            'per_day_per_email' => 5,
        ],

        // Consultation d'un signalement avec son jeton de suivi (signalant sans compte), par IP.
        'moderation_lookups' => [
            'per_hour' => 30,
        ],

        // Recours contre une décision de modération, par compte ou par IP.
        'moderation_appeals' => [
            'per_day' => 5,
        ],

        // Décisions de la modération des signalements (décision, levée, recours, signalement aux
        // autorités), par admin : limite l'effet d'un compte admin compromis.
        'moderation_admin' => [
            'per_hour' => 60,
        ],

        // Marketplace (lot 3), par utilisateur : identité professionnelle, offres, devis.
        'seller_profile_writes' => [
            'per_hour' => 20,
        ],
        'offer_writes' => [
            'per_hour' => 60,
            'per_day' => 300,
        ],
        // Brouillons de devis (création, modification, suppression, nouvelle version).
        'quote_writes' => [
            'per_hour' => 120,
            'per_day' => 600,
        ],
        // Envoi d'un devis au client (email) : plus strict, signe de démarchage au-delà.
        'quote_sends' => [
            'per_hour' => 20,
            'per_day' => 60,
        ],
        // Acceptation, refus, retrait et rétractation.
        'quote_decisions' => [
            'per_hour' => 30,
        ],
        // PDF générés à la demande (devis et comparaison) : coûteux en mémoire.
        'quote_pdfs' => [
            'per_hour' => 30,
            'per_day' => 200,
        ],
        'quote_comparisons' => [
            'per_hour' => 60,
        ],

        // Rendez-vous (lot 4), par utilisateur sauf mention.
        // Réglages, types, plages et exceptions du prestataire.
        'appointment_settings_writes' => [
            'per_hour' => 60,
            'per_day' => 300,
        ],
        // Créneaux calculés à la demande (aperçu, relation) : par utilisateur ; page publique :
        // par IP (collecte de l'emploi du temps d'un prestataire).
        'appointment_slots' => [
            'per_minute' => 60,
            'per_day' => 1000,
            'public_per_minute_per_ip' => 30,
        ],
        // Réservations, propositions et reports (emails à l'autre partie). La limite par jour
        // fixe aussi le plafond des propositions en attente d'un prestataire (30, décision 54).
        'appointment_bookings' => [
            'per_hour' => 10,
            'per_day' => 30,
        ],
        // Confirmation, refus, annulation, réponse à un report.
        'appointment_decisions' => [
            'per_hour' => 60,
        ],
        // Fichier .ics d'un rendez-vous.
        'appointment_ics' => [
            'per_hour' => 30,
        ],
        // Agenda unifié (GET /me/agenda).
        'agenda' => [
            'per_minute' => 60,
        ],
        // Abonnement unique à l'agenda : création, remplacement, révocation.
        'agenda_feed_manage' => [
            'per_day' => 10,
        ],
        // Abonnement unique (route publique à jeton) : par abonnement et par IP.
        'agenda_feed' => [
            'per_hour_per_feed' => 30,
            'per_hour_per_ip' => 120,
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

        // Événements (module Events, purge quotidienne events:prune).
        // Inscriptions aux événements terminés depuis cette durée : supprimées (seul le nombre
        // d'inscrits reste sur l'événement).
        'event_registrations_months' => 12,

        // Révisions refusées, retirées ou appliquées : supprimées (la version publiée et le
        // journal d'audit restent).
        'event_revisions_months' => 6,

        // Annonces aux inscrits des événements terminés depuis cette durée : supprimées.
        'event_announcements_months' => 12,

        // Événements annulés, ou détachés de leur organisateur (compte supprimé), terminés
        // depuis cette durée : supprimés avec leur couverture.
        'closed_events_months' => 12,

        // Articles (module Blog, purge quotidienne blog:prune).
        // Révisions validées, refusées, retirées ou remplacées : supprimées (la version publiée
        // et le journal d'audit restent).
        'article_revisions_months' => 6,

        // Brouillons jamais soumis et sans modification depuis cette durée : supprimés avec
        // leurs images.
        'abandoned_article_drafts_months' => 12,

        // Articles refusés ou dépubliés, sans modification depuis cette durée : supprimés avec
        // leurs images (le décompte du quota gratuit reste).
        'closed_articles_months' => 12,

        // Slugs d'articles supprimés : gardés pour répondre 410 et empêcher qu'un autre article
        // les reprenne, puis supprimés.
        'retired_article_slugs_months' => 24,

        // Suivi administratif (module Compliance, purge quotidienne compliance:prune). Ces données
        // ont une valeur pour l'utilisateur : la date de la prochaine purge est annoncée dans
        // l'API, et l'export reste possible à tout moment.
        // Échéances passées (et leurs tâches) : supprimées après cette durée.
        'compliance_deadlines_months' => 36,

        // Chiffre d'affaires : supprimé par années civiles entières, au-delà de ce nombre
        // d'années complètes (les années N-1 et N-2 servent aux seuils).
        'compliance_revenue_years' => 6,

        // Alertes de seuil et parcours terminés : supprimés après cette durée.
        'compliance_threshold_alerts_months' => 36,
        'compliance_journeys_months' => 36,

        // Journal d'audit des décisions d'administration (table admin_audit_logs) : supprimé
        // après cette durée (admin-audit:prune).
        'admin_audit_months' => 24,

        // Signalements et modération (module Moderation, purge quotidienne moderation:prune).
        // Identité et email du signalant, explication, précision sur l'emplacement et copie du
        // contenu signalé : effacés après cette durée, comptée depuis la clôture du signalement
        // (ou depuis sa réception s'il est encore ouvert).
        'moderation_notices_months' => 12,

        // Décisions (exposés des motifs), recours et signalements clos : supprimés après cette
        // durée, comptée depuis la fin du délai de recours ou la levée de la restriction (la
        // plus tardive), sans recours en attente. Le journal d'audit suit sa propre durée.
        'moderation_records_months' => 24,

        // Devis (module Marketplace, purge quotidienne marketplace:prune, décision 47).
        // Brouillons sans modification depuis cette durée : supprimés.
        'marketplace_drafts_months' => 12,
        // Devis refusés, retirés, expirés ou remplacés depuis cette durée : supprimés. Les devis
        // acceptés restent tant que leur conversation existe.
        'marketplace_closed_quotes_months' => 12,

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
