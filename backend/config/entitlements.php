<?php

/*
|--------------------------------------------------------------------------
| Droits par fonctionnalité (décision 5 révisée, docs/v1/DECISIONS.md)
|--------------------------------------------------------------------------
|
| Deux plans seulement : "free" et "premium". Un seul abonnement Premium, au même prix pour
| tous ; ses avantages dépendent du rôle de l'utilisateur. Lu par l'Action publique
| App\Modules\Accounts\Actions\GetEntitlements (plan actuel : Action IsPremium). Les plans en
| base et le paiement arriveront au lot Billing.
|
| Valeurs d'une fonctionnalité :
| - entier : limite ; null : illimité ;
| - booléen : fonctionnalité ouverte ou non ;
| - liste : choix proposés ;
| - ['config' => 'cle'] : valeur lue dans une autre configuration au moment de la lecture.
|   Les valeurs actuelles du Blog et de Compliance restent ainsi dans config/blog.php et
|   config/compliance.php (une seule source, réglable par les tests).
|
| Une fonctionnalité absente pour un rôle n'est pas ouverte : limite 0, booléen false,
| liste vide. Aucun taux, seuil ni montant réglementaire ici.
*/

$professional = [
    'free' => [
        // Articles soumis au Blog (quota gratuit, compté à la première soumission).
        'blog.article_quota' => ['config' => 'blog.free_articles_quota'],
        // Suivi administratif.
        'compliance.companies' => ['config' => 'compliance.max_companies_free'],
        'compliance.active_journeys' => ['config' => 'compliance.max_active_journeys_free'],
        'compliance.reminder_offsets' => ['config' => 'compliance.reminders.free_offsets'],
        'compliance.max_reminder_offsets' => 1,
        'compliance.custom_threshold_levels' => false,
        'compliance.estimate' => false,
        'compliance.calendar_feed' => true,
        'compliance.export' => true,
    ],
    'premium' => [
        'blog.article_quota' => null,
        'compliance.companies' => ['config' => 'compliance.max_companies_premium'],
        'compliance.active_journeys' => null,
        'compliance.reminder_offsets' => ['config' => 'compliance.reminders.premium_offsets'],
        'compliance.max_reminder_offsets' => ['config' => 'compliance.reminders.premium_max_offsets'],
        'compliance.custom_threshold_levels' => true,
        'compliance.estimate' => true,
        'compliance.calendar_feed' => true,
        'compliance.export' => true,
    ],
];

/*
| Avantages propres au prestataire (décision 35), ajoutés à ceux de $professional.
*/
$provider = [
    'free' => [
        // Projets visibles seulement après la fenêtre d'accès anticipé
        // (config/directory.php, early_access_hours ; décision 44).
        'directory.project_early_access' => false,
        // Consulter le détail des projets (GET /projects) et faire des propositions : Premium
        // seulement (décision 81). Sans Premium, le prestataire voit la vitrine publique.
        // config/directory.php, free_project_access (false en production).
        'directory.project_access' => ['config' => 'directory.free_project_access'],
        // Devis PDF personnalisés (logo et mentions, décisions 35 et 43) ; le PDF standard
        // reste gratuit.
        'marketplace.quote_branding' => false,
        // Rendez-vous (décisions 52 et 54) : page de réservation publique et quotas
        // (config/appointments.php, plans).
        'appointments.public_booking_page' => false,
        'appointments.active_types' => ['config' => 'appointments.plans.free.active_types'],
        'appointments.weekly_ranges' => ['config' => 'appointments.plans.free.weekly_ranges'],
        'appointments.upcoming_exceptions' => ['config' => 'appointments.plans.free.upcoming_exceptions'],
        'appointments.horizon_days' => ['config' => 'appointments.plans.free.horizon_days'],
        'appointments.upcoming_appointments' => ['config' => 'appointments.plans.free.upcoming_appointments'],
        'appointments.reminder_offsets' => ['config' => 'appointments.plans.free.reminder_offsets'],
        'appointments.max_reminder_offsets' => ['config' => 'appointments.plans.free.max_reminder_offsets'],
    ],
    'premium' => [
        'directory.project_early_access' => true,
        'directory.project_access' => true,
        'marketplace.quote_branding' => true,
        'appointments.public_booking_page' => true,
        'appointments.active_types' => ['config' => 'appointments.plans.premium.active_types'],
        'appointments.weekly_ranges' => ['config' => 'appointments.plans.premium.weekly_ranges'],
        'appointments.upcoming_exceptions' => ['config' => 'appointments.plans.premium.upcoming_exceptions'],
        'appointments.horizon_days' => ['config' => 'appointments.plans.premium.horizon_days'],
        'appointments.upcoming_appointments' => ['config' => 'appointments.plans.premium.upcoming_appointments'],
        'appointments.reminder_offsets' => ['config' => 'appointments.plans.premium.reminder_offsets'],
        'appointments.max_reminder_offsets' => ['config' => 'appointments.plans.premium.max_reminder_offsets'],
    ],
];

/*
| Avantages du porteur de projet (particulier, entrepreneur ; décisions 33, 35 et 43) :
| comparateur de devis et export PDF de la comparaison. La lecture de chaque devis reçu et son
| PDF restent gratuits.
*/
$client = [
    'free' => [
        'marketplace.quote_comparison' => false,
        'marketplace.quote_comparison_export' => false,
    ],
    'premium' => [
        'marketplace.quote_comparison' => true,
        'marketplace.quote_comparison_export' => true,
    ],
];

/*
| Workspaces et copilote de projet (particulier, entrepreneur ; décisions 61 et 63) : quotas dans
| config/workspaces.php (plans). Gratuit : un workspace non archivé, deux feuilles de route
| générées à vie (une et une régénération), pas de cahier des charges ni de modèle de projet.
| Premium : dix workspaces, modèles, 30 générations par mois et 10 par jour.
*/
$workspace = [
    'free' => [
        'workspaces.active' => ['config' => 'workspaces.plans.free.active_workspaces'],
        'workspaces.templates' => false,
        'copilot.roadmap_generations' => ['config' => 'workspaces.plans.free.roadmap_generations'],
        'copilot.specifications' => false,
        'copilot.monthly_generations' => null,
        'copilot.daily_generations' => null,
    ],
    'premium' => [
        'workspaces.active' => ['config' => 'workspaces.plans.premium.active_workspaces'],
        'workspaces.templates' => true,
        'copilot.roadmap_generations' => null,
        'copilot.specifications' => true,
        'copilot.monthly_generations' => ['config' => 'workspaces.plans.premium.monthly_generations'],
        'copilot.daily_generations' => ['config' => 'workspaces.plans.premium.daily_generations'],
    ],
];

return [

    'plans' => ['free', 'premium'],

    /*
    | Avantages par rôle et par plan. Les rôles de l'équipe (redacteur, admin) n'ont aucun
    | avantage d'abonnement. Les limites de projets et de demandes des particuliers passeront ici
    | au lot Billing (décision 19).
    */
    'roles' => [
        'particulier' => [
            'free' => [...$client['free'], ...$workspace['free']],
            'premium' => [...$client['premium'], ...$workspace['premium']],
        ],
        'prestataire' => [
            'free' => [...$professional['free'], ...$provider['free']],
            'premium' => [...$professional['premium'], ...$provider['premium']],
        ],
        'entrepreneur' => [
            'free' => [...$professional['free'], ...$client['free'], ...$workspace['free']],
            'premium' => [...$professional['premium'], ...$client['premium'], ...$workspace['premium']],
        ],
    ],

];
