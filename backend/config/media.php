<?php

/*
|--------------------------------------------------------------------------
| Brique média (images)
|--------------------------------------------------------------------------
|
| Service partagé du noyau (App\Support\Media). Les limites propres à chaque
| usage (poids, dimensions, rôles) sont définies dans l'enum MediaPurpose.
|
*/

return [

    // Disque des nouveaux médias : "public" en local, "r2" en production.
    // Chaque média enregistre son disque : changer cette valeur ne casse pas les anciens.
    'disk' => env('MEDIA_DISK', 'public'),

    // Qualité de l'encodage WebP (0 à 100).
    'webp_quality' => 82,

    // Garde-fou contre les "bombes de décompression" : vérifié sur l'en-tête, avant décodage.
    'max_pixels' => 25_000_000,
    'max_side' => 8000,

    // Nombre maximal de médias non rattachés par utilisateur (au-delà : 429).
    'max_pending_per_user' => 20,

    // Âge (en heures) à partir duquel un média jamais rattaché est supprimé.
    'orphan_ttl_hours' => 24,

];
