<?php

/*
|--------------------------------------------------------------------------
| Catégories de notifications (noyau)
|--------------------------------------------------------------------------
|
| Chaque notification envoyée par la plateforme déclare sa catégorie
| (App\Support\Notifications\CategorizedNotification). L'utilisateur peut désactiver une
| catégorie facultative (GET|PUT /me/notification-preferences) ; une catégorie obligatoire
| (sécurité du compte, information sur un engagement pris, décision de modération motivée)
| part toujours. Par défaut, tout est activé.
|
| - mandatory : ne peut pas être désactivée ;
| - roles : rôles qui voient et règlent cette catégorie ;
| - label : libellé affiché par le frontend.
|
| Canaux : seulement "mail" aujourd'hui.
*/

$everyone = ['particulier', 'prestataire', 'entrepreneur', 'redacteur', 'admin'];
$members = ['particulier', 'prestataire', 'entrepreneur'];

return [

    'channels' => ['mail'],

    'categories' => [

        // Noyau et Accounts : vérification de l'email, mot de passe, tentative d'inscription.
        'account.security' => [
            'mandatory' => true,
            'roles' => $everyone,
            'label' => ['fr' => 'Sécurité du compte', 'en' => 'Account security'],
        ],

        // Directory : mise en relation et messagerie.
        'directory.match_requests' => [
            'mandatory' => false,
            'roles' => $members,
            'label' => ['fr' => 'Demandes de mise en relation (reçues, acceptées, refusées)', 'en' => 'Connection requests (received, accepted, declined)'],
        ],
        'directory.messages' => [
            'mandatory' => false,
            'roles' => $members,
            'label' => ['fr' => 'Nouveaux messages', 'en' => 'New messages'],
        ],

        // Marketplace : devis (décision 46).
        'marketplace.quotes' => [
            'mandatory' => false,
            'roles' => $members,
            'label' => ['fr' => 'Devis reçus et refusés', 'en' => 'Quotes received and declined'],
        ],
        // Acceptation d'un devis (contrat, PDF joint) et rétractation : toujours envoyés.
        'marketplace.quote_decisions' => [
            'mandatory' => true,
            'roles' => $members,
            'label' => ['fr' => 'Devis acceptés et rétractations', 'en' => 'Accepted quotes and withdrawals'],
        ],

        // Rendez-vous (décision 56) : demandes, confirmations, refus, annulations, reports et
        // expirations (engagement pris : toujours envoyés) ; rappels (facultatifs).
        'appointments.bookings' => [
            'mandatory' => true,
            'roles' => $members,
            'label' => ['fr' => 'Rendez-vous (demande, confirmation, annulation, report)', 'en' => 'Appointments (request, confirmation, cancellation, change)'],
        ],
        'appointments.reminders' => [
            'mandatory' => false,
            'roles' => $members,
            'label' => ['fr' => 'Rappels avant un rendez-vous', 'en' => 'Reminders before an appointment'],
        ],

        // Events : participant.
        'events.participation' => [
            'mandatory' => true,
            'roles' => $everyone,
            'label' => ['fr' => 'Inscriptions aux événements (confirmation, changement important, annulation)', 'en' => 'Event registrations (confirmation, important change, cancellation)'],
        ],
        'events.reminders' => [
            'mandatory' => false,
            'roles' => $everyone,
            'label' => ['fr' => 'Rappel la veille d\'un événement', 'en' => 'Reminder the day before an event'],
        ],
        'events.announcements' => [
            'mandatory' => false,
            'roles' => $everyone,
            'label' => ['fr' => 'Annonces des organisateurs', 'en' => 'Announcements from organisers'],
        ],

        // Events : organisateur (décisions de modération motivées).
        'events.organizer' => [
            'mandatory' => true,
            'roles' => ['prestataire', 'entrepreneur', 'redacteur', 'admin'],
            'label' => ['fr' => 'Décisions sur mes événements', 'en' => 'Decisions about my events'],
        ],
        'events.moderation_digest' => [
            'mandatory' => false,
            'roles' => ['admin'],
            'label' => ['fr' => 'Récapitulatif de la modération des événements', 'en' => 'Event moderation digest'],
        ],

        // Blog.
        'blog.author' => [
            'mandatory' => true,
            'roles' => ['prestataire', 'entrepreneur'],
            'label' => ['fr' => 'Décisions sur mes articles', 'en' => 'Decisions about my articles'],
        ],
        'blog.moderation_digest' => [
            'mandatory' => false,
            'roles' => ['redacteur', 'admin'],
            'label' => ['fr' => 'Récapitulatif de la modération du blog', 'en' => 'Blog moderation digest'],
        ],

        // Compliance. Les réglages propres au suivi administratif (emails_enabled, délais)
        // restent dans le module ; cette catégorie coupe aussi ses récapitulatifs.
        'compliance.reminders' => [
            'mandatory' => false,
            'roles' => ['prestataire', 'entrepreneur'],
            'label' => ['fr' => 'Rappels du suivi administratif', 'en' => 'Admin tracking reminders'],
        ],
        'compliance.rule_coverage' => [
            'mandatory' => false,
            'roles' => ['admin'],
            'label' => ['fr' => 'Mise à jour annuelle des règles administratives', 'en' => 'Annual update of administrative rules'],
        ],

        // Moderation (DSA). Accusé de réception et décision envoyés au signalant (art. 16 §4 et
        // §5), exposé des motifs envoyé à l'auteur du contenu (art. 17), issue d'un recours :
        // obligatoires. Un signalant sans compte les reçoit à l'adresse qu'il a donnée.
        'moderation.notices' => [
            'mandatory' => true,
            'roles' => $everyone,
            'label' => ['fr' => 'Suivi de mes signalements', 'en' => 'Updates on my reports'],
        ],
        'moderation.decisions' => [
            'mandatory' => true,
            'roles' => $everyone,
            'label' => ['fr' => 'Décisions de modération sur mes contenus', 'en' => 'Moderation decisions about my content'],
        ],
        // Admins : alerte immédiate sur un signalement urgent (sans contenu), et récapitulatif.
        'moderation.urgent_alerts' => [
            'mandatory' => true,
            'roles' => ['admin'],
            'label' => ['fr' => 'Alertes de signalements urgents', 'en' => 'Urgent report alerts'],
        ],
        'moderation.digest' => [
            'mandatory' => false,
            'roles' => ['admin'],
            'label' => ['fr' => 'Récapitulatif des signalements et recours', 'en' => 'Reports and appeals digest'],
        ],

    ],

];
