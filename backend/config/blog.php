<?php

/*
|--------------------------------------------------------------------------
| Module Blog
|--------------------------------------------------------------------------
|
| Réglages métier du module. Les limites de débit et les durées de conservation sont dans
| config/security.php (rate_limits.article_*, rate_limits.blog_moderation, retention.article_*).
|
*/

return [

    // Langues acceptées pour un article (codes ISO 639-1). L'équipe ne modère que ces langues :
    // élargir cette liste suppose des modérateurs qui les lisent.
    'languages' => ['fr', 'en'],

    /*
    | Quota gratuit des prestataires et des entrepreneurs sans statut premium : nombre d'articles
    | soumis à la validation (première soumission de chaque article, définitivement comptée,
    | même si l'article est refusé, retiré ou supprimé ensuite, et même pendant une période
    | premium). Au-delà : 403 avec "code": "premium_required".
    */
    'free_articles_quota' => 2,

    // null : quota à vie. Un nombre de mois : quota sur une période glissante.
    'free_quota_period_months' => null,

    // Articles en attente de validation en même temps, par auteur (premium compris).
    'max_pending_per_author' => 3,

    // Brouillons en même temps, par auteur (équipe comprise).
    'max_drafts_per_author' => 20,

    // Nouvelles soumissions d'un même article après un refus ou une dépublication.
    'max_resubmissions' => 5,

    // Catégories de services par article.
    'max_categories' => 3,

    // Nombre minimal de mots du corps pour soumettre un article.
    'min_words' => 200,

    // Vitesse de lecture pour le temps de lecture affiché, en mots par minute.
    'words_per_minute' => 230,

    // Articles liés affichés sous un article (même langue, catégorie commune).
    'related_count' => 3,

    // Articles par page de GET /articles/sitemap.
    'sitemap_per_page' => 1000,

    // Les modérateurs reçoivent au plus un email de récapitulatif par période, en minutes.
    'moderation_digest_minutes' => 30,

    // Nom sous lequel l'équipe (rédacteurs et admins) signe ses articles.
    'editorial_name' => [
        'fr' => 'La rédaction Loopartner',
        'en' => 'The Loopartner editorial team',
    ],

    /*
    | Corps d'un article (document JSON de blocs, App\Modules\Blog\Support\ArticleBody).
    */
    'body' => [
        // Taille maximale de la requête d'écriture d'un article (413 au-delà), en kilo-octets.
        'max_request_kilobytes' => 256,
        'max_blocks' => 300,
        // Caractères de texte au total (segments, textes alternatifs, légendes, sources).
        'max_characters' => 40000,
        // Caractères d'un segment de texte.
        'max_text_length' => 2000,
        // Segments de texte par bloc ou par élément de liste.
        'max_segments' => 200,
        'max_list_items' => 50,
        'max_images' => 10,
        'max_links' => 30,
    ],

];
