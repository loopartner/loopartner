<?php

/*
|--------------------------------------------------------------------------
| Module Compliance (suivi administratif des indépendants et des entreprises)
|--------------------------------------------------------------------------
|
| Réglages techniques et commerciaux du module. AUCUNE valeur fiscale ou sociale ici (statut,
| échéance, seuil, taux) : ce sont des règles en base (table compliance_rules), vérifiées par
| l'équipe avec leur source officielle. Les limites de débit et les durées de conservation sont
| dans config/security.php (rate_limits.compliance_*, retention.compliance_*).
|
*/

return [

    /*
    | Pays pris en charge par l'application (code ISO 3166-1 alpha-2) : devise des montants et
    | fuseau horaire des échéances. Un pays n'est proposé aux utilisateurs que s'il a aussi des
    | règles vérifiées pour l'année en cours (voir RuleCatalog::availableCountries()).
    */
    'countries' => [
        'FR' => ['currency' => 'EUR', 'timezone' => 'Europe/Paris'],
    ],

    /*
    | Principe des « quatre yeux » : l'admin qui vérifie une règle ne peut pas en être le dernier
    | auteur (403). Désactivé tant que l'équipe ne compte qu'un admin : la vérification réelle est
    | faite par un expert-comptable externe, sans compte admin, et la note de vérification
    | (obligatoire) indique qui a vérifié, quand et sur quelle source. À activer
    | (COMPLIANCE_REQUIRE_DISTINCT_VERIFIER=true) dès que deux admins peuvent se relire.
    */
    'require_distinct_verifier' => (bool) env('COMPLIANCE_REQUIRE_DISTINCT_VERIFIER', false),

    // Longueur minimale de la note de vérification d'une règle (qui, quand, sur quelle source).
    'verification_note_min_length' => 30,

    // Échéances générées à l'avance, en mois à partir d'aujourd'hui.
    'calendar_horizon_months' => 15,

    // Entreprises par utilisateur.
    'max_companies_free' => 1,
    'max_companies_premium' => 5,

    // Échéances personnelles à venir par entreprise, et tâches par échéance ou par parcours.
    'max_custom_deadlines' => 50,
    'max_tasks_per_parent' => 30,

    // Parcours actifs (non terminés) par entreprise, sans premium.
    'max_active_journeys_free' => 1,

    /*
    | Rappels par email (un récapitulatif par utilisateur et par jour au plus). Délais en jours
    | avant l'échéance : un seul délai parmi free_offsets sans premium ; jusqu'à
    | premium_max_offsets délais parmi premium_offsets avec premium.
    */
    'reminders' => [
        'default_offsets' => [7],
        'free_offsets' => [3, 7, 14],
        'premium_offsets' => [1, 3, 7, 14, 30],
        'premium_max_offsets' => 3,
    ],

    /*
    | Paliers d'alerte des seuils, en pourcentage du seuil. Sans premium : default_levels. Avec
    | premium : jusqu'à premium_max_levels paliers entre premium_min et 100, par pas de step.
    */
    'thresholds' => [
        'default_levels' => [80, 100],
        'premium_min' => 50,
        'step' => 5,
        'premium_max_levels' => 3,
    ],

    // Chiffre d'affaires : montant maximal d'une saisie (centimes) et ancienneté maximale (années).
    'revenue' => [
        'max_amount' => 99_999_999_999,
        'max_years_back' => 10,
    ],

    /*
    | Mise à jour annuelle des règles : du 1er octobre au 30 novembre, la tâche
    | compliance:check-rule-coverage signale aux admins (au plus un email tous les digest_days
    | jours) les règles sans version vérifiée pour l'année suivante, et les règles sans date de
    | fin vérifiées avant le stale_before de l'année en cours.
    */
    'coverage' => [
        'window_start' => '10-01',
        'window_end' => '11-30',
        'stale_before' => '09-01',
        'digest_days' => 7,
    ],

    // Version du texte d'avertissement (« ne remplace pas un expert-comptable »), renvoyée avec
    // le texte pour que le frontend sache quand il change.
    'disclaimer_version' => 1,

];
