<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Profil public des prestataires
    |--------------------------------------------------------------------------
    |
    | Décision 82 : un profil ne se publie (et ne reste publié lors d'une modification) qu'avec
    | une photo (logo), une bannière, une description d'au moins min_bio_length caractères et au
    | moins une catégorie. require_complete_profile n'est désactivé que par phpunit.xml, pour les
    | tests qui publient un profil minimal ; tests/Feature/Accounts/ProviderProfileCompletenessTest.php
    | le réactive. Ne le désactivez pas en production.
    |
    */

    'provider_profile' => [
        'require_complete_profile' => filter_var(env('PROVIDER_PROFILE_REQUIRE_COMPLETE', true), FILTER_VALIDATE_BOOL),
        'min_bio_length' => 80,
    ],

];
