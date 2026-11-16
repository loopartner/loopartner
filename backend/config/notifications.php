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

    ],

];
