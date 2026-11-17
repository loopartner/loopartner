<?php

/*
|--------------------------------------------------------------------------
| Module Directory (projets, mise en relation)
|--------------------------------------------------------------------------
|
| Réglages métier du module. Limites de débit et durées de conservation : config/security.php.
*/

return [

    /*
    | Accès anticipé aux nouveaux projets (décisions 33 et 44) : pendant ce nombre d'heures
    | après sa publication (ou sa republication), un projet n'est visible que des prestataires
    | dont les droits ouvrent directory.project_early_access (config/entitlements.php, Premium).
    | L'ordre des résultats reste le même pour tous. 0 : pas de fenêtre d'accès anticipé.
    */
    'early_access_hours' => (int) env('DIRECTORY_EARLY_ACCESS_HOURS', 24),

];
