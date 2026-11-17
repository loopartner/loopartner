<?php

use App\Modules\Accounts\Support\ProviderProfileModerationTarget;
use App\Modules\Appointments\Support\AppointmentTypeModerationTarget;
use App\Modules\Blog\Support\ArticleModerationTarget;
use App\Modules\Directory\Support\Moderation\MatchRequestModerationTarget;
use App\Modules\Directory\Support\Moderation\MessageModerationTarget;
use App\Modules\Directory\Support\Moderation\ProjectModerationTarget;
use App\Modules\Events\Support\AnnouncementModerationTarget;
use App\Modules\Events\Support\EventModerationTarget;
use App\Modules\Marketplace\Support\OfferModerationTarget;
use App\Modules\Marketplace\Support\QuoteModerationTarget;

/*
|--------------------------------------------------------------------------
| Signalements et modération (module Moderation, règlement (UE) 2022/2065 « DSA »)
|--------------------------------------------------------------------------
|
| Réglages du traitement des signalements (art. 16), des exposés des motifs (art. 17) et du
| recours interne (aligné sur l'art. 20, appliqué volontairement : voir docs/v1/DECISIONS.md).
| Les textes destinés aux utilisateurs sont dans docs/v1/moderation-rules.md (à faire valider
| par le juriste). Limites de débit et durées de conservation : config/security.php.
*/

return [

    /*
    | Types de contenus signalables : type stable => adaptateur du module qui possède le
    | contenu (App\Support\Moderation\ModerationTarget). Un nouveau contenu public (offres,
    | avis…) ajoute ici son adaptateur : un test vérifie que chaque contenu public en a un.
    */
    'targets' => [
        'provider_profile' => ProviderProfileModerationTarget::class,
        'project' => ProjectModerationTarget::class,
        'message' => MessageModerationTarget::class,
        'match_request' => MatchRequestModerationTarget::class,
        'event' => EventModerationTarget::class,
        'event_announcement' => AnnouncementModerationTarget::class,
        'article' => ArticleModerationTarget::class,
        'offer' => OfferModerationTarget::class,
        'quote' => QuoteModerationTarget::class,
        'appointment_type' => AppointmentTypeModerationTarget::class,
    ],

    /*
    | Catégories proposées au signalant (décision 22, libellés à faire valider par le juriste).
    |
    | - priority : "urgent" (alerte immédiate aux admins, en tête de file) ou "standard" ;
    | - identity_optional : nom et email facultatifs (art. 16 §2 c : infractions des articles 3
    |   à 7 de la directive 2011/93/UE) ;
    | - transparency_category : catégorie de la base de transparence de la Commission
    |   (documentation de l'API lue sur https://transparency.dsa.ec.europa.eu/page/api-documentation
    |   le 2026-11-16), pour un futur rapport ; correspondance à faire valider par le juriste.
    */
    'categories' => [
        'scam_fraud' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_SCAMS_AND_FRAUD',
            'label' => ['fr' => 'Arnaque ou fraude', 'en' => 'Scam or fraud'],
        ],
        'hate_harassment' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_ILLEGAL_OR_HARMFUL_SPEECH',
            'label' => ['fr' => 'Contenu haineux ou harcèlement', 'en' => 'Hateful content or harassment'],
        ],
        'privacy' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_DATA_PROTECTION_AND_PRIVACY_VIOLATIONS',
            'label' => ['fr' => 'Atteinte à la vie privée ou aux données personnelles', 'en' => 'Privacy or personal data violation'],
        ],
        'intellectual_property' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_INTELLECTUAL_PROPERTY_INFRINGEMENTS',
            'label' => ['fr' => 'Contrefaçon ou atteinte à la propriété intellectuelle', 'en' => 'Counterfeit or intellectual property infringement'],
        ],
        'illegal_activity' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_NOT_SPECIFIED_NOTICE',
            'label' => ['fr' => 'Activité ou profession illégale', 'en' => 'Illegal activity or profession'],
        ],
        'child_sexual_abuse' => [
            'priority' => 'urgent',
            'identity_optional' => true,
            'transparency_category' => 'STATEMENT_CATEGORY_PROTECTION_OF_MINORS',
            'label' => ['fr' => 'Abus sexuels sur mineurs', 'en' => 'Child sexual abuse'],
        ],
        'violence_terrorism' => [
            'priority' => 'urgent',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_VIOLENCE',
            'label' => ['fr' => 'Incitation à la violence ou terrorisme', 'en' => 'Incitement to violence or terrorism'],
        ],
        'misleading_commercial' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_CONSUMER_INFORMATION',
            'label' => ['fr' => 'Pratique commerciale trompeuse', 'en' => 'Misleading commercial practice'],
        ],
        'terms' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_OTHER_VIOLATION_TC',
            'label' => ['fr' => 'Contraire aux CGU (spam, hors sujet, faux profil)', 'en' => 'Against the terms of use (spam, off-topic, fake profile)'],
        ],
        'other' => [
            'priority' => 'standard',
            'identity_optional' => false,
            'transparency_category' => 'STATEMENT_CATEGORY_NOT_SPECIFIED_NOTICE',
            'label' => ['fr' => 'Autre', 'en' => 'Other'],
        ],
    ],

    /*
    | Objectifs de traitement, en heures, selon la priorité de la catégorie : la file
    | d'administration signale les signalements en retard. Un hébergeur doit agir
    | « promptement » une fois informé (art. 6 et 16 §3) : ce ne sont pas des délais légaux.
    */
    'processing_hours' => [
        'urgent' => 24,
        'standard' => 168,
    ],

    // Recours interne : ouvert pendant ce nombre de mois après la décision (art. 20 §1 : au
    // moins six mois), au plus max_appeals_per_decision recours par personne et par décision,
    // un seul en attente à la fois.
    'appeal_window_months' => 6,
    'max_appeals_per_decision' => 3,

    // L'admin qui a pris une décision ne peut pas trancher le recours contre elle (art. 20 §6 :
    // contrôle par des collaborateurs qualifiés). false par défaut tant que l'équipe ne compte
    // qu'un admin ; security:check-config avertit quand deux admins ont la 2FA.
    'require_distinct_appeal_reviewer' => (bool) env('MODERATION_REQUIRE_DISTINCT_APPEAL_REVIEWER', false),

    // Points de contact uniques (art. 11 : autorités, Commission et comité ; art. 12 :
    // utilisateurs), publiés par GET /moderation/info. Vérifiés par security:check-config en
    // production.
    'contact' => [
        'users_email' => env('MODERATION_CONTACT_EMAIL'),
        'authorities_email' => env('MODERATION_AUTHORITIES_EMAIL'),
        'languages' => ['fr', 'en'],
    ],

    /*
    | Clauses des CGU citées par les décisions fondées sur les conditions générales
    | (art. 17 §3 e). "reference" est le numéro de l'article des CGU rédigées par le juriste :
    | security:check-config échoue en production tant qu'une clause n'en a pas.
    */
    'terms_clauses' => [
        'publication_rules' => [
            'reference' => null,
            'label' => ['fr' => 'Règles de publication des contenus', 'en' => 'Content publication rules'],
        ],
        'prohibited_content' => [
            'reference' => null,
            'label' => ['fr' => 'Contenus interdits', 'en' => 'Prohibited content'],
        ],
        'accurate_profile' => [
            'reference' => null,
            'label' => ['fr' => 'Exactitude du profil et identité', 'en' => 'Profile accuracy and identity'],
        ],
        'spam_and_solicitation' => [
            'reference' => null,
            'label' => ['fr' => 'Spam et sollicitations abusives', 'en' => 'Spam and abusive solicitation'],
        ],
        'respectful_conduct' => [
            'reference' => null,
            'label' => ['fr' => 'Respect des autres utilisateurs', 'en' => 'Respect for other users'],
        ],
    ],

    // Clause citée par les décisions prises dans la modération avant publication (Events,
    // Blog), où le modérateur ne choisit pas de clause.
    'pre_publication_clause' => 'publication_rules',

    // Récapitulatif aux admins (moderation:notify-moderators) : au plus un email par période.
    'digest_minutes' => 30,

    // Longueurs des textes saisis (caractères).
    'limits' => [
        'explanation' => ['min' => 20, 'max' => 5000],
        'location_detail' => 500,
        'reporter_name' => 120,
        'facts' => ['min' => 30, 'max' => 3000],
        'correction_hint' => 1000,
        'legal_reference' => 255,
        'appeal' => ['min' => 30, 'max' => 5000],
        'reason' => ['min' => 10, 'max' => 1000],
        // Copie textuelle du contenu signalé (au-delà : tronquée).
        'snapshot' => 20000,
    ],

];
