<?php

/*
|--------------------------------------------------------------------------
| Workspaces et copilote de projet (lot 5, décisions 61, 63 et 64)
|--------------------------------------------------------------------------
|
| Quotas par plan (lus par config/entitlements.php), limites de contenu et réglages du
| copilote. Durées de conservation : config/security.php (retention). Brique d'IA :
| config/ai.php.
*/

return [

    /*
    | Quotas par plan (particuliers et entrepreneurs, décisions 61 et 63).
    | - active_workspaces : workspaces non archivés ;
    | - roadmap_generations : feuilles de route générées, à vie (null : illimité) ;
    | - monthly_generations, daily_generations : générations du Premium (feuilles de route et
    |   cahiers des charges confondus ; null : pas de limite).
    */
    'plans' => [
        'free' => [
            'active_workspaces' => 1,
            'roadmap_generations' => 2,
        ],
        'premium' => [
            'active_workspaces' => 10,
            'monthly_generations' => 30,
            'daily_generations' => 10,
        ],
    ],

    // Workspaces au total, archivés compris (tous plans).
    'max_workspaces' => 50,

    // Limites de contenu d'un workspace (décision 63).
    'limits' => [
        'goals' => 20,
        'steps' => 30,
        'tasks_per_step' => 50,
        'tasks' => 300,
        'specifications' => 20,
    ],

    // Longueurs maximales des textes saisis.
    'lengths' => [
        'title' => 160,
        'description' => 5000,
        'note' => 2000,
        'section' => 3000,
    ],

    /*
    | Copilote de projet (décisions 61, 64 et 66). Les réponses de l'IA sont nettoyées puis
    | bornées par ces valeurs avant d'être gardées comme suggestion.
    */
    'copilot' => [
        'max_steps' => 12,
        'max_tasks_per_step' => 8,
        'max_categories' => 6,
    ],

];
