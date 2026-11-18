<?php

/*
|--------------------------------------------------------------------------
| Brique d'IA du noyau (lot 5, décisions 34 et 60 à 70)
|--------------------------------------------------------------------------
|
| Les modules n'appellent l'IA que par App\Support\Ai\AiGateway, qui choisit le pilote
| (contrat App\Support\Ai\AiClient), vérifie la disponibilité, réserve le coût sous verrou et
| journalise sans aucun contenu. Changer de fournisseur ne touche aucun module.
|
| Pilotes :
| - "disabled" (défaut, production comprise) : la fonctionnalité est indisponible, sans erreur ;
| - "fake" : réponses simulées, pour les tests et la démonstration (jamais en production) ;
| - "mistral" : Mistral AI, endpoint régional de l'UE imposé (décision 60).
|
| L'IA ne doit pas être activée sur l'hébergement mutualisé : l'appel est synchrone (décision 70).
| Étapes avant l'activation : docs/v1/workspaces-rules.md, section 7.
*/

return [

    'driver' => env('AI_DRIVER', 'disabled'),

    // Modèle utilisé par toutes les fonctionnalités (identifiant exact du fournisseur).
    'model' => env('AI_MODEL', 'mistral-small-2603'),

    // Délai maximal d'un appel, en secondes. Le max_execution_time de PHP doit être nul ou au
    // moins égal à ce délai plus la marge (security:check-config, et refus 503 à l'appel).
    'timeout_seconds' => (int) env('AI_TIMEOUT', 45),
    'execution_time_margin_seconds' => 15,

    // Plafond de sortie par génération (jetons).
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 3000),

    /*
    | Plafonds de coût (décision 62), en cents de la devise du fournisseur. Le coût de chaque
    | appel est réservé avant l'appel (entrée estimée + sortie maximale) puis ajusté au coût
    | réel. Alertes aux admins à ces pourcentages du budget mensuel global.
    */
    'currency' => 'USD',
    'budgets' => [
        'user_monthly_cents' => (int) env('AI_USER_MONTHLY_BUDGET_CENTS', 50),
        'global_monthly_cents' => (int) env('AI_GLOBAL_MONTHLY_BUDGET_CENTS', 5000),
        'global_daily_cents' => (int) env('AI_GLOBAL_DAILY_BUDGET_CENTS', 500),
        'alert_levels' => [80, 100],
    ],

    /*
    | Prix par modèle, en micro-unités de la devise par million de jetons (1 $ = 1 000 000).
    | Ce ne sont pas des montants réglementaires : ce sont les prix publics du fournisseur,
    | relevés le 2026-11-18 (docs/v1/workspaces-rules.md), majorés de 10 % pour l'endpoint
    | régional de l'UE. Un modèle sans prix rend l'IA indisponible.
    */
    'pricing' => [
        'mistral-small-2603' => ['input' => 165_000, 'output' => 660_000],
        'fake-model' => ['input' => 165_000, 'output' => 660_000],
    ],

    'providers' => [
        'mistral' => [
            'label' => 'Mistral AI',
            'base_url' => env('MISTRAL_BASE_URL', 'https://api.eu.mistral.ai'),
            // Seul hôte accepté : l'endpoint régional de l'UE (le pilote refuse tout autre).
            'eu_host' => 'api.eu.mistral.ai',
            'api_key' => env('MISTRAL_API_KEY'),
            // Raisonnement réduit au minimum : réponse plus rapide, sans trace de raisonnement.
            'reasoning_effort' => env('MISTRAL_REASONING_EFFORT', 'none'),
            'temperature' => 0.3,
        ],
        'fake' => [
            'label' => 'Simulation (tests)',
        ],
    ],

];
