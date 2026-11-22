# Loopartner — règles du projet

Loopartner est un SaaS qui met en relation des particuliers porteurs de projets et des prestataires.
Il propose aussi un agenda d'événements, un blog et un suivi des obligations administratives des
entrepreneurs.

> Ces règles sont prioritaires sur les consignes génériques générées par Laravel Boost
> (`backend/CLAUDE.md`, `backend/AGENTS.md`) en cas de conflit.

## V1 2027 : feuille de route et décisions (à lire au début de chaque session)

- **`docs/v1/ROADMAP.md`** : lots 0 à 11 de la V1 commercialisable (Billing est le lot 10 ; le lot 3 Marketplace est fusionné, validations externes en attente ; le lot 4 Rendez-vous et agenda est fusionné, validation du juriste en attente ; le lot 5 Workspaces et onboarding est fusionné, validation du juriste en attente ; le prochain est le lot 6 ; le frontend est démarré en parallèle, décisions 71 à 74), avec leurs critères
  d'acceptation et leur état. Ne commence jamais un lot sans validation explicite de son plan
  détaillé par l'utilisateur.
- **`docs/v1/DECISIONS.md`** : décisions produit et techniques validées, datées. Ne les remets pas
  en cause sans le demander ; une nouvelle décision s'y ajoute avec sa date.
- Rappels : pas de refonte ; aucun taux, seuil ou montant réglementaire dans le code ; aucun
  module ni parcours incomplet présenté comme terminé ; base MySQL/MariaDB (jamais PostgreSQL) ;
  le frontend est développé en parallèle des lots 6 à 11 (décision 2 modifiée le 2026-11-20) ; un plan est
  toujours présenté avant de coder, les décisions purement techniques y sont tranchées (décision 20).

## Dépôt

- `backend/` : API Laravel 13, PHP 8.4. Elle ne sert aucune vue : elle renvoie uniquement du JSON.
- `frontend/` : Next.js 16 et TypeScript (voir « Frontend »), déployé sur Vercel (dossier racine
  `frontend`), servi sur un autre sous-domaine du même domaine que l'API. Il consomme l'API avec
  des cookies de session.
- `docs/v1/` : feuille de route et décisions de la V1 (voir ci-dessus), règles de la modération
  (`moderation-rules.md`), de la facturation (`billing-rules.md`), de la Marketplace
  (`marketplace-rules.md`), des rendez-vous (`appointments-rules.md`) et des workspaces et de
  l'IA (`workspaces-rules.md`), à faire valider.
- `.github/workflows/ci.yml` : intégration continue (voir « Qualité »).
- `SECURITY.md` : signalement des vulnérabilités, principes de sécurité, journal de sécurité et
  vérifications avant chaque mise en production. La liste de contrôle de chaque tâche est la section
  « Sécurité : liste de contrôle obligatoire » ci-dessous.

## Environnement local

- Le `php` du PATH Windows est en PHP 8.4 : utilise directement `php`, `composer` et `vendor/bin/...`.
- Base de données locale : MariaDB/MySQL via XAMPP (configurée dans `backend/.env` par l'utilisateur).
  Les tests utilisent SQLite en mémoire (`phpunit.xml`).
- **Liste de contrôle des migrations** (tous les modules, y compris Blog et Compliance) :
  - Elles doivent fonctionner sur MySQL, MariaDB et SQLite : jeu de caractères `utf8mb4`, longueur
    explicite et raisonnable pour les chaînes indexées (191 caractères maximum pour un index sur une
    seule colonne `string`), pas de type ni de fonction propre à un seul moteur.
  - **Une date obligatoire s'écrit `dateTime()` (valeur en UTC), jamais `timestamp()`** :
    `timestamp()` et `timestampTz()` seulement avec `->nullable()` ou `->useCurrent()`
    (`timestamps()` et `softDeletes()` restent permis). Raison : sur MariaDB 10.4 (XAMPP) et MySQL
    sans `explicit_defaults_for_timestamp`, la première colonne `TIMESTAMP NOT NULL` d'une table
    reçoit implicitement `ON UPDATE CURRENT_TIMESTAMP` et sa valeur serait réécrite à chaque
    modification de la ligne, ce que SQLite (tests) ne montre pas.
  - Vérifié par `tests/Arch/MigrationsTest.php` (lecture du code des migrations) et, sur la base
    réelle, par `security:check-config` (erreur sur toute colonne `ON UPDATE CURRENT_TIMESTAMP` hors
    `updated_at`).
  - **Aucun nom de table, d'index, de clé unique ou de clé étrangère au-delà de 64 caractères**
    (limite de MySQL et MariaDB, erreur 1059 ; SQLite et `migrate --pretend` ne la voient pas).
    Le nom généré par Laravel est `<table>_<colonnes>_<unique|index|foreign>` : s'il est trop
    long, donner un nom court explicite (`$table->unique([...], 'compliance_revenue_entries_unique')`).
    Vérifié par `tests/Feature/Core/MigrationIdentifiersTest.php`, qui exécute toutes les
    migrations et contrôle chaque nom réel ou généré.
  - Une migration déjà exécutée ne se modifie pas : toute correction passe par une nouvelle migration.
- **Ne lis jamais et ne modifie jamais `backend/.env`**. Cette interdiction est aussi bloquée dans
  `.claude/settings.json`. Seul `backend/.env.example` se modifie.
- **Ne lance jamais de commande Artisan destructrice pour la base** : `migrate:fresh`,
  `migrate:refresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, ni aucune commande Artisan
  avec `--force`. Raison : `backend/.env` pointe vers la base locale de l'utilisateur (XAMPP), qui
  contient des données qu'il n'a pas forcément sauvegardées ; pendant le module Blog,
  `migrate:fresh` a été lancé par erreur et n'a été refusé que par chance. Ces commandes sont
  bloquées dans `.claude/settings.json` (outils Bash et PowerShell). Pour vérifier une migration :
  `php artisan migrate --pretend` ; les tests utilisent SQLite en mémoire. Si une remise à zéro
  est nécessaire, demande à l'utilisateur de la lancer lui-même.
- N'installe aucun package Composer ou npm sans l'accord explicite de l'utilisateur.

## Contraintes de production (Hostinger mutualisé)

- Base de données : MySQL. Pas de Redis, pas de processus permanent (ni worker, ni Horizon, ni Reverb).
- Cache, sessions et files d'attente utilisent le driver `database`.
- Les jobs sont traités par le planificateur : une tâche cron lance `php artisan schedule:run` chaque
  minute, et le planificateur exécute `queue:work --stop-when-empty --max-time=50` chaque minute,
  sans chevauchement (verrou expirant après 5 minutes). Le worker s'arrête dès que la file est vide,
  et au plus tard après 50 secondes. Tâche cron Hostinger :
  `* * * * * cd <chemin>/backend && php artisan schedule:run >> /dev/null 2>&1`.
- Les tâches planifiées sont déclarées dans `routes/console.php`, et le worker de file d'attente
  reste déclaré en dernier. `tests/Feature/SchedulerTest.php` vérifie la planification de chaque
  tâche et la position du worker. Tâches actuelles : `projects:close-expired` (02:00),
  `match-requests:expire` (02:30), `media:prune-orphans` (03:00), `matching:prune` (03:30),
  `events:send-reminders` (toutes les 15 minutes), `events:close-past` (chaque heure),
  `events:prune` (04:00), `events:notify-moderators` (toutes les 5 minutes), `blog:prune`
  (05:00), `blog:notify-moderators` (toutes les 5 minutes), `admin-audit:prune` (04:30),
  `compliance:extend-calendars` (01:30), `compliance:prune` (05:30), `compliance:send-reminders`
  (06:00), `compliance:check-rule-coverage` (06:30, actif du 1er octobre au 30 novembre),
  `moderation:prune` (05:45), `moderation:notify-moderators` (toutes les 5 minutes),
  `marketplace:expire-quotes` (chaque heure), `marketplace:prune` (05:15),
  `appointments:expire` (toutes les 15 minutes), `appointments:send-reminders` (toutes les
  5 minutes), `appointments:prune` (04:45), `workspaces:prune` (04:15), `ai:prune` (04:20),
  puis le worker.
- Toutes les dates sont stockées en **UTC** (`app.timezone = UTC`, connexion MySQL en `+00:00`).
  La conversion vers le fuseau de l'utilisateur se fait à l'affichage, côté frontend.

## Architecture modulaire

Le code métier vit dans `backend/app/Modules/<Module>/`. Les modules sont : `Accounts`, `Directory`,
`Events`, `Blog`, `Compliance`, `Moderation`, `Marketplace`, `Appointments` et `Workspaces`.

Chaque module contient :

```
Models/  Http/Controllers/  Http/Requests/  Http/Resources/  Policies/  Actions/  Jobs/  routes.php
```

Et, au besoin : `Enums/` (enums PHP), `Rules/` (règles de validation), `Support/` (petites classes
utilitaires du module), `Console/Commands/` (commandes Artisan du module), `Notifications/`
(emails), `Events/` (événements publics, que d'autres modules peuvent écouter) et `Listeners/`
(écouteurs, découverts automatiquement par `bootstrap/app.php`, `withEvents`).

- Les commandes des modules sont chargées par `bootstrap/app.php`
  (`withCommands(glob('app/Modules/*/Console/Commands'))`), en plus de `app/Console/Commands`.
  Une commande de module se nomme `<Nom>Command` pour ne pas se confondre avec l'Action qu'elle appelle.

- `App\Providers\ModuleServiceProvider` charge automatiquement chaque `routes.php` avec le préfixe
  `/api/v1`, le middleware `api` et `throttle:api`. Pour ajouter un module, il suffit de créer son
  dossier et son `routes.php`.
- **Règle d'isolation** : un module n'utilise jamais directement les modèles d'un autre module
  (`App\Modules\X\Models\*` est interdit depuis `App\Modules\Y`). Pour communiquer entre modules, on
  passe par des Actions publiques, des événements ou des identifiants. Un test d'architecture Pest
  vérifie cette règle (`tests/Arch/ModulesTest.php`).
- **Exception** : `App\Models\User` est le modèle partagé du projet, tous les modules peuvent l'utiliser.
  Il en va de même pour la brique média du noyau (`App\Models\Media` et `App\Support\Media`, voir la
  section « Brique média ») et pour les catégories de services (`App\Models\ServiceCategory`, voir la
  section « Catégories de services »). Le noyau, lui, ne dépend jamais d'un module.
- Un module peut lire les enums publics d'un autre module (par exemple `AccountType` pour les rôles),
  jamais ses modèles.
- Règles de validation partagées du noyau, dans `app/Rules` (namespace `App\Rules`), utilisables par
  tous les modules : `NoHtml` (refuse le HTML), `CountryCode` (ISO 3166-1 alpha-2), `CurrencyCode`
  (ISO 4217), `LanguageCode` (ISO 639-1), `SafeExternalUrl` (lien externe : https, nom de domaine
  public, ni IP, ni hôte local, ni raccourcisseur, ni domaine refusé) et `SafeLinksInText` (mêmes
  règles pour les liens écrits dans un texte libre ; `App\Support\Links\ExternalLink`). Les listes de
  codes sont statiques (ni package ni extension intl).
- Journal d'audit des décisions d'administration (noyau) : `App\Support\AdminAudit::record()`
  (table `admin_audit_logs`, lignes jamais modifiées, `admin_id` sans clé étrangère), purgé après
  `security.retention.admin_audit_months` (24) par `admin-audit:prune`.
- Fonctions d'administration : middleware du noyau `App\Http\Middleware\EnsureModerator` (rôle admin
  sinon 404 ; 2FA confirmée et session ouverte avec un code 2FA sinon 403). Les rôles acceptés se
  passent en paramètre (`EnsureModerator::class.':admin,redacteur'` pour le Blog ; `admin` par
  défaut). La session est marquée par `App\Listeners\TrackTwoFactorSession`
  (`App\Support\TwoFactorSession`) : un admin qui vient d'activer sa 2FA doit se reconnecter avec
  son code.
- Briques de modération du noyau : `App\Support\Moderation\VersionGuard` (version lue par le
  modérateur, 409 sinon), `App\Support\Links\LinkInspection` (liens regroupés par domaine,
  `punycode`, `previously_approved`), `App\Support\RateLimit\ActionThrottle` (limite de débit
  comptée dans une Action, sur plusieurs fenêtres) et le middleware
  `App\Http\Middleware\LimitRequestBody:<octets>` (413 avant la validation). Events et Blog les
  utilisent tous les deux (`Events\Support\Moderation` ne garde que l'éligibilité de
  l'organisateur). `VersionGuard::assertSameFingerprint()` contrôle l'empreinte d'un contenu sans
  colonne de version.
- Contenus signalables (noyau, DSA) : contrat `App\Support\Moderation\ModerationTarget`, un
  adaptateur par type de contenu déclaré dans `config/moderation.php` (`targets`), registre
  `ModerationTargets`, données `TargetContent`, restriction `Restriction` (colonnes
  `moderation_restricted_at` et `moderation_decision_uuid`) et événement `ContentModerated`
  (décision restrictive d'Events ou du Blog). Voir « Module Moderation ».
- Calendrier iCalendar du noyau : `App\Support\Calendar\Ics` (échappement, repli des lignes à
  75 octets, dates UTC et journées entières, enveloppe `VCALENDAR`), utilisé par Events (fichier
  d'un événement, sortie figée par `tests/Fixtures/ics`), Compliance (abonnement aux échéances)
  et Appointments (fichier d'un rendez-vous). Jeton d'abonnement : `App\Support\Calendar\FeedToken`
  (sélecteur de 16 caractères, secret de 40 dont seule l'empreinte HMAC est stockée, temps
  constant), utilisé par l'abonnement à l'agenda et par Compliance (`CalendarFeedToken` délègue).
- Heure locale du noyau : `App\Support\Time\LocalTime` (heure locale d'une date vers UTC ; heure
  inexistante du passage à l'heure d'été avancée à l'instant du changement ; heure ambiguë :
  première occurrence pour un début, dernière pour une fin ; `isValidTimezone()` : fuseaux IANA
  canoniques).
- **Agenda unifié** (noyau) : contrat `App\Support\Agenda\AgendaSource`, un adaptateur par module
  déclaré dans `config/agenda.php` (`sources` : Appointments, Events, Compliance, Workspaces), `AgendaItem`
  (données simples), `Agenda::for()`, `AgendaCalendar` (abonnement : titres et dates seulement).
  `GET /me/agenda` (`from`, `to` en journées UTC, 92 jours au plus, `sources[]`, champ
  `disclaimers` par source), `GET|POST|DELETE /me/agenda/feed` (URL montrée une fois) et
  `GET /agenda/{token}.ics` (public, à jeton, table `agenda_feeds`, éléments confirmés ou
  publiés, jeton invalide journalisé au plus une fois par heure et par IP). L'abonnement de
  Compliance reste disponible.
- **Brique d'IA** (`App\Support\Ai`) et **parcours d'accueil** (`App\Support\Onboarding`) : voir
  « Brique d'IA et parcours d'accueil (noyau) ».
- `TrimStrings` ne rogne pas `body.blocks.*` (corps des articles : les espaces entre segments
  comptent), voir `bootstrap/app.php`.
- Langues : `App\Support\Locale` (`fr`, `en`). `Locale::fromRequest()` choisit la langue d'une réponse :
  paramètre `locale`, puis en-tête `Accept-Language`, puis `fr`. Une valeur inconnue est ignorée.
- Pagination des listes : `App\Support\Pagination` (paramètres `page` et `per_page`, 20 par défaut,
  50 au maximum, 422 au-delà). Réponse paginée standard de Laravel (`data`, `links`, `meta`).
- Le test d'architecture vérifie que le noyau (`App\Models`, `App\Rules`, `App\Support`, `App\Http`,
  `App\Console`) ne dépend d'aucun module.
- Les routes du noyau (hors modules, par exemple `POST /media`) sont dans `routes/api.php`, sous le
  même préfixe `/api/v1`, avec `api` et `throttle:api`.
- Les actions Fortify restent dans `app/Actions/Fortify`.
- Les modèles de module déclarent leur factory avec l'attribut `#[UseFactory(...)]`, car la découverte
  automatique ne cherche que dans `App\Models`. Leurs factories vivent dans
  `database/factories/Modules/<Module>/` (namespace `Database\Factories\Modules\<Module>`).
- `App\Models\User` ne déclare aucune relation vers un modèle de module : c'est le module qui
  interroge ses propres tables par `user_id`.
- Les Policies sont découvertes automatiquement dans `App\Modules\<Module>\Policies`.
- Conventions : Form Requests pour la validation, API Resources pour les réponses, Policies pour
  l'autorisation, Actions pour les opérations métier, Jobs pour les tâches différées.

## Module Accounts

- Gère le type de compte à l'inscription, les préférences (`locale`, `timezone`, `country` sur `users`),
  les endpoints `/me`, le profil prestataire et la suppression de compte.
- L'inscription exige `account_type` (`App\Modules\Accounts\Enums\AccountType`) : `particulier`,
  `prestataire` ou `entrepreneur`. Les rôles `redacteur` et `admin` ne s'obtiennent jamais par
  l'inscription. Le rôle est attribué par l'Action `CreateAccount`, dans la même transaction,
  appelée par `RegisterAccount` (voir « Parcours d'inscription et d'authentification »).
- **`ProviderProfile` est privé au module Accounts** : aucun autre module ne l'importe, ni ses
  Resources, ni sa factory. Un module qui a besoin d'informations sur un prestataire passe par une
  Action publique du module Accounts (à créer au besoin), qui renvoie des données simples (tableau ou
  DTO), jamais le modèle Eloquent. Actions publiques existantes : `HasPublishedProviderProfile`,
  `FindPublishedProvider` (slug d'un profil publié → `user_id` et résumé public) et
  `GetProviderSummaries` (résumés publics de plusieurs prestataires en une requête : `display_name`,
  `slug` (null si dépublié), `headline`, `city`, `country`, `logo_url`),
  `PublishedProviderConstraint` (limite une requête d'un autre module aux prestataires au profil
  publié et non masqué, par sous-requête) et `GetProviderLogo` (média du logo, devis PDF).
- Événement public `Accounts\Events\AccountDeleting` : envoyé de façon synchrone par `DeleteAccount`,
  dans sa transaction et avant la suppression de la ligne `users`. Les autres modules y traitent les
  données de l'utilisateur (Directory : `ForgetUserMatching` ; Events : `ForgetUserEvents` ;
  Moderation : `ForgetUserModeration`). Un écouteur ne doit pas être mis en file
  d'attente ; une exception annule toute la suppression.
- Le slug d'un profil prestataire est calculé à la création puis ne change plus (URL stables).
- Les champs texte saisis par les utilisateurs refusent le HTML (règle `NoHtml`) plutôt que de le
  nettoyer. Le frontend doit quand même échapper ces textes à l'affichage.
- Avatar (`PATCH /me`, champ `avatar`) et logo du profil prestataire (`PUT /me/provider-profile`,
  champ `logo`) : uuid d'un média envoyé par `POST /media`, ou `null` pour le retirer. Dans le PUT,
  un `logo` absent laisse le logo inchangé (même exception que `status`). Les réponses exposent
  `avatar_url` et `logo_url` (null si absent).
- Catégories du profil prestataire (`PUT /me/provider-profile`, champ `categories`) : liste de 1 à 5
  slugs de catégories actives, qui remplace la précédente. Absent : inchangé (comme `logo`). Un
  brouillon peut n'avoir aucune catégorie, mais **la publication exige au moins une catégorie** (422
  sur `categories`). Table pivot `provider_profile_category`. Les Resources exposent `categories`
  (slug et nom traduit).
- `GET /providers` : recherche publique des profils **publiés**, triée par `published_at` décroissant.
  Filtres facultatifs (un paramètre vide est ignoré) : `category` (slug actif, sous-catégories
  incluses), `country`, `city` (égalité sans tenir compte de la casse) et `q` (2 à 100 caractères,
  dans `display_name`, `headline` et `bio`). Même Resource que la page publique.
- **Colonnes JSON** : le type JSON natif de MySQL (production) trie les clés de chaque objet à
  l'écriture ; MariaDB et SQLite gardent l'ordre. Un document relu en base ne se compare donc
  jamais avec `===` à un tableau construit par l'application : `App\Support\Database\JsonDocument`
  (`same()`, `canonical()`), comme `Blog\Support\ArticleContent::changedFields()`.
- La recherche texte est un simple `LIKE` (`App\Support\Database\Like`, qui échappe `%`, `_` et `!`
  avec `ESCAPE '!'`, compatible SQLite et MariaDB 10.4). Une recherche plein texte (index FULLTEXT ou
  moteur dédié) pourra la remplacer plus tard si le volume l'exige.
- **Statut premium** (`PremiumGrant`, table `premium_grants`, privé au module) : historique de
  périodes (`source` `manual` aujourd'hui, `payment` plus tard avec `external_reference`),
  `starts_at` en DATETIME, `ends_at` facultatif, `revoked_at`. Actif = période commencée, ni
  terminée ni retirée (calculé à la lecture, sans tâche). Actions publiques `IsPremium` et
  `GetPremiumStatus` (`active`, `source`, `starts_at`, `ends_at`, sans motif ni admin).
  `GET /me` expose `premium` et `article_quota` (Action publique `Blog\Actions\GetArticleQuota` :
  `premium`, `limit`, `used`, `remaining` pour un prestataire ou un entrepreneur, `null` sinon).
  Administration : `/admin/premium-grants` (admin seul avec 2FA de session, `EnsureModerator`) :
  `GET ?email=` (historique, 404 si compte inconnu), `POST` (`email`, `starts_at`, `ends_at`,
  `reason` ; 201 ; 409 si une période est en cours ou à venir ; 422 pour l'équipe ou un compte
  inconnu), `POST {uuid}/revoke` (`reason` ; 409 si terminée, retirée ou payée). Le compte est
  désigné par son email (l'id interne n'est jamais exposé ; le journal n'en garde qu'une
  empreinte). Journal d'audit (`accounts.premium.granted|revoked`, avec le motif), canal security
  (`premium.granted|revoked`), limiteur `premium-admin`.
- **Droits par fonctionnalité** (décision 5 révisée : Gratuit et un seul Premium, avantages selon
  le rôle) : Action publique `GetEntitlements` (`EntitlementSet` : `plan` `free`/`premium` via
  `IsPremium`, `role`, `features`) et `GET /me/entitlements`. Grille dans
  `config/entitlements.php` (par rôle puis par plan) : entier = limite, `null` = illimité,
  booléen, liste de choix, ou `['config' => 'cle']` (valeur lue à l'appel dans
  `config/blog.php` ou `config/compliance.php` ; une clé inconnue lève une exception, jamais
  « illimité »). Une fonctionnalité absente est fermée. Les noms contiennent un point : ne pas
  utiliser la notation pointée de `config()` pour une fonctionnalité. Blog (`blog.article_quota`),
  Compliance (`compliance.*`), Directory (`directory.project_early_access`), Marketplace
  (`marketplace.quote_branding`, `marketplace.quote_comparison`,
  `marketplace.quote_comparison_export`), Appointments (`appointments.*`) et Workspaces
  (`workspaces.active`, `workspaces.templates`, `copilot.roadmap_generations`,
  `copilot.specifications`, `copilot.monthly_generations`, `copilot.daily_generations`) lisent
  cette grille. Plans en base et paiement : lot Billing.
- Données de référence (rôles, catégories) : `ReferenceDataSeeder`, seul seeder à lancer en production :
  `php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force`.
- Comptes de démonstration créés par `DatabaseSeeder` **uniquement si** `SEED_DEMO_DATA=true`,
  `APP_ENV=local`, `APP_DEBUG=true` et `APP_URL` locale (`DemoDataGuard`). Si `SEED_DEMO_DATA=true`
  sans les autres conditions, le seed échoue. Comptes : `<rôle>@example.com`
  pour chaque rôle, mot de passe `password`, email vérifié. Le prestataire a un profil publié avec
  trois catégories ; le particulier et l'entrepreneur ont chacun un projet publié et un brouillon
  (`DatabaseSeeder::DEMO_PROJECTS`, repérés par leur titre). Relancer le seeder republie un projet de
  démonstration expiré. Mise en relation de démonstration (sans aucun email) : proposition du
  prestataire acceptée sur le projet du particulier, avec une conversation
  (`DatabaseSeeder::DEMO_MESSAGES`) ; proposition en attente sur le projet de l'entrepreneur ; contact
  de l'entrepreneur vers le prestataire, refusé. Événements de démonstration (sans aucun email) :
  publiés (atelier en présentiel, webinaire, conférence hybride avec une révision en attente, salon
  payant publié par l'admin), un en attente, un refusé, un passé, et des inscriptions entre les
  comptes. Blog (`DatabaseSeeder::DEMO_ARTICLES`, sans aucun email) : deux articles publiés par la
  rédaction (rédacteur, admin) ; prestataire **premium** (attribution manuelle) avec un article
  publié et une révision en attente, et un article en attente ; entrepreneur au **quota gratuit
  atteint** (un article publié, un refusé avec `DEMO_ARTICLE_REJECTION_REASON`) et un brouillon.
  Suivi administratif (`ComplianceDemoSeeder`, sans aucun email) : règles **fictives** (`is_demo`,
  codes `demo.*`, titres « [Démo] ») ; micro-entreprise de l'entrepreneur avec calendrier, parcours,
  tâche, échéance personnelle et chiffre d'affaires à 85 % d'un seuil ; deux entreprises pour le
  prestataire premium. Marketplace (`MarketplaceDemoSeeder`, sans aucun email) : taux de TVA
  **fictif** (`demo.vat.rate.standard`, `is_demo`), identité professionnelle fictive du
  prestataire, deux offres publiées, un devis envoyé au particulier dans leur mise en relation
  acceptée. Rendez-vous (`AppointmentsDemoSeeder`, sans aucun email) : calendrier et page publique
  du prestataire (deux types, plages du lundi au vendredi), un rendez-vous confirmé et une
  proposition en attente avec le particulier. Workspaces (`WorkspacesDemoSeeder`, sans email ni
  appel à l'IA) : workspace du particulier (objectif, étapes dont une issue du copilote, tâches,
  suggestion fictive en attente, une génération décomptée) et workspace de l'entrepreneur copié
  du modèle « Lancer mon activité ».
- **Double authentification de l'équipe de démonstration** (obligatoire pour l'équipe) :
  `redacteur@example.com` a aussi un secret fixe, `DatabaseSeeder::DEMO_REDACTEUR_TWO_FACTOR_SECRET`
  (`LOOPARTNERDEMORD`, nom `Loopartner rédacteur (démo)`, codes de secours
  `DEMO_REDACTEUR_RECOVERY_CODES`), à utiliser comme celui de l'admin ci-dessous.
- **Documents légaux de démonstration** : version `DatabaseSeeder::DEMO_LEGAL_VERSION` (`demo-1`,
  `is_demo`, textes « [Démo] » sans valeur juridique) des CGU et de la politique de
  confidentialité, acceptée automatiquement par chaque compte de démonstration.
- **Double authentification de `admin@example.com`** (démonstration uniquement) : activée et
  confirmée par le seeder avec un secret fixe, `DatabaseSeeder::DEMO_ADMIN_TWO_FACTOR_SECRET`
  (`LOOPARTNERDEMOAD`), pour tester la modération. Pour se connecter : ajouter un compte dans une
  application d'authentification (Google Authenticator, Microsoft Authenticator, 2FAS, Aegis…) en
  **saisie manuelle de la clé** : nom `Loopartner admin (démo)`, clé `LOOPARTNERDEMOAD`, type
  « basé sur le temps » (TOTP, 6 chiffres, 30 secondes). Se connecter avec `admin@example.com` /
  `password`, puis envoyer le code affiché à `POST /api/v1/auth/two-factor-challenge`. Codes de
  secours : `DatabaseSeeder::DEMO_ADMIN_RECOVERY_CODES`. Ce secret est public, comme le mot de passe
  des comptes de démonstration : `DemoDataGuard` empêche de le créer hors du poste local, et **une base
  de démonstration ne doit jamais être copiée vers un autre environnement** (préproduction,
  production, sauvegarde partagée).

## Brique média (images)

Service partagé du noyau, utilisable par tous les modules : modèle `App\Models\Media` (table `media`)
et code dans `backend/app/Support/Media/`.

### Fonctionnement

1. Le frontend envoie l'image à `POST /api/v1/media` (`multipart/form-data` : `file`, `purpose`). Il
   reçoit en 201 `uuid`, `purpose`, `url`, `width` et `height`. Le média n'est encore rattaché à rien
   (`attached_at` null).
2. Il envoie ensuite cet `uuid` à l'endpoint du module concerné (ex. `PATCH /me` avec `avatar`), qui
   rattache le média.
3. Un média jamais rattaché est supprimé, avec son fichier, après 24 heures par la commande
   planifiée `media:prune-orphans` (chaque jour à 03:00).

Usages (`App\Support\Media\MediaPurpose`, limites définies dans l'enum) :

| purpose | Poids max | Min | Max (réduit sans agrandir) | Rôles |
|---|---|---|---|---|
| `avatar` | 5 Mo | 128×128 | 512×512 | tous |
| `provider_logo` | 5 Mo | 128×128 | 1024×1024 | prestataire |
| `event_cover` | 5 Mo | 960×540 | 1920×1080 | prestataire, entrepreneur, rédacteur, admin |
| `article_cover` | 5 Mo | 1200×630 | 2400×1260 | prestataire, entrepreneur, rédacteur, admin |
| `article_image` | 5 Mo | 320×200 | 1600×1600 | prestataire, entrepreneur, rédacteur, admin |

Plusieurs médias d'un même usage (images du corps d'un article) se rattachent avec
`MediaLibrary::attachMany()` (revérifiés sous verrou) ; le module supprime ensuite avec `delete()`
ceux qu'il ne garde plus.

### Utiliser la brique depuis un module

- Ajouter une colonne `<champ>_media_id` (FK vers `media`, `nullable`, `nullOnDelete`) dans la table
  du module, et une relation `belongsTo(Media::class, '<champ>_media_id')`.
- Valider l'uuid reçu avec la règle `new AttachableMedia($user, MediaPurpose::X, $currentMediaId)`
  (`['sometimes', 'nullable', 'string', new AttachableMedia(...)]`). Elle n'accepte qu'un média de
  l'utilisateur, du bon usage, libre ou déjà rattaché à ce champ. Sinon : 422.
- Rattacher dans l'Action, dans une transaction :
  `$id = $mediaLibrary->replace($user, MediaPurpose::X, $uuid, $currentMediaId, '<champ>')`, puis
  enregistrer `$id` dans la colonne. `replace()` revérifie le média sous verrou, remplit `attached_at`,
  et supprime l'ancien média et son fichier (`null` retire le média).
- Exposer `'<champ>_url' => $this->relation?->url()` dans la Resource. N'exposer **jamais** l'id
  interne, le disque ni le chemin d'un média : seuls son `uuid` et son URL sont publics.
- Ne jamais supprimer un média avec `$media->delete()` ni par une cascade SQL : passer par
  `MediaLibrary::delete()` / `deleteOwnedBy()`, qui suppriment aussi les fichiers (après le commit).
  `media.user_id` est en `restrict` : la base refuse de supprimer un utilisateur qui a encore des
  médias. `DeleteAccount` appelle donc `deleteOwnedBy()` en premier.
- Pour ouvrir un nouvel usage : renvoyer un `PurposeLimits` depuis `MediaPurpose::limits()` (poids,
  dimensions, rôles sous forme de chaînes, pour que le noyau ne dépende pas des enums des modules).

### Règles de sécurité

- Upload réservé aux utilisateurs connectés avec un email vérifié (403 sinon). Rôle requis selon le
  purpose (403).
- Formats acceptés : **JPEG, PNG et WebP uniquement**, vérifiés sur le contenu réel (finfo puis
  en-tête de l'image), jamais sur l'extension. SVG, GIF et les faux fichiers image sont refusés (422).
- Garde-fou contre les « bombes de décompression » : au-delà de 8000 px de côté ou de 25 mégapixels,
  l'image est refusée sur la base de son en-tête, avant tout décodage.
- Toute image est décodée puis **réencodée en WebP** par Intervention Image (GD), qualité 82 :
  l'orientation EXIF est appliquée, puis les métadonnées (EXIF, GPS) et tout contenu caché
  disparaissent. L'image est réduite aux dimensions maximales du purpose, sans agrandissement.
- Le fichier est nommé `<purpose>/<AAAA>/<MM>/<uuid>.webp`. Le nom d'origine n'est jamais repris.
- Limiteur `media-uploads` : 20 uploads par minute et par utilisateur (429).
- Au plus 20 médias non rattachés par utilisateur (`media.max_pending_per_user`). Au-delà, 429 avec
  un message explicite.

### Configuration

- `config/media.php` : disque, qualité WebP, plafonds de pixels, médias en attente, délai de nettoyage.
- Variables d'environnement :
  - `MEDIA_DISK` : `public` en local, `r2` en production. Chaque média enregistre son disque, donc
    changer cette valeur ne casse pas les médias existants.
  - Disque `r2` (Cloudflare R2, pilote S3) : `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`,
    `R2_ENDPOINT` (`https://<account_id>.r2.cloudflarestorage.com`) et `R2_PUBLIC_URL` (domaine
    public du bucket, sans slash final).
- En local, avec `MEDIA_DISK=public`, lancer une fois `php artisan storage:link` pour servir
  `storage/app/public` sous `APP_URL/storage`.
- Tests : `phpunit.xml` impose `MEDIA_DISK=public`. Les tests utilisent `Storage::fake('public')` et
  des images générées par GD (`testImage()`, `jpegWithMetadata()` dans `tests/Pest.php`).

### Contraintes de production (à vérifier au déploiement)

- PHP : `upload_max_filesize` et `post_max_size` d'**au moins 8 Mo**. Sinon, l'upload échoue
  avant même la validation.
- Extension **GD compilée avec le support WebP** (`php -r "var_dump(gd_info()['WebP Support']);"`).
  Les extensions `exif` (orientation des photos) et `fileinfo` sont aussi nécessaires.
- Bucket R2 rendu public (domaine personnalisé ou `r2.dev`), avec `R2_PUBLIC_URL` pointant dessus.

## Module Directory (projets)

- `Project` (table `projects`) : projet d'un particulier ou d'un entrepreneur. Identifiant public :
  `uuid`. Statut `App\Modules\Directory\Enums\ProjectStatus` (`draft`, `published`, `closed`).
  Budget facultatif en unités entières (`budget_min` ≤ `budget_max`, égalité acceptée), devise ISO 4217
  (`EUR` par défaut), pays obligatoire, texte brut (`NoHtml`).
- Gestion par le propriétaire, sous `/me/projects` (`OwnProjectController`) : liste, création (toujours
  en brouillon, email vérifié non requis), détail, modification partielle (PATCH, tout statut), suppression,
  `POST …/publish` et `POST …/close`.
- `ProjectPolicy` : seuls les rôles `particulier` et `entrepreneur` gèrent des projets (403 sinon). Le
  projet d'un autre utilisateur donne **404** (`denyAsNotFound`), même publié. Pour un PATCH,
  l'autorisation passe avant la validation (`UpdateProjectRequest::authorize()`).
- Publication (`PublishProject`) : email vérifié (403), depuis un brouillon, un projet clos ou expiré.
  Elle fixe `published_at`, `expires_at` (+60 jours, `Project::PUBLICATION_DAYS`) et remet `closed_at` à
  null. Erreurs d'état en **409** : projet déjà publié et non expiré, ou plafond de 10 projets publiés et
  non expirés atteint (`Project::MAX_PUBLISHED_PER_USER`, compté sous verrou de la ligne `users`).
  Clôture (`CloseProject`) : uniquement un projet publié, sinon 409.
- Un projet est visible des prestataires s'il est publié **et** non expiré (scope `live()`), même avant la
  clôture quotidienne `projects:close-expired`.
- `GET /projects` et `GET /projects/{uuid}` : prestataires connectés avec un email vérifié **et un
  profil prestataire publié avec au moins une catégorie** (`auth:sanctum`, `verified`,
  `ProjectPolicy::browse`, qui appelle l'Action publique `Accounts\Actions\HasPublishedProviderProfile` ;
  403 avec un message explicite sinon). Limiteur dédié `projects-browse` (30/min et 500/jour par
  prestataire, liste et détail confondus). Chaque consultation est journalisée dans le canal
  `security` (`RecordProjectConsultation` : utilisateur, IP, route, page, filtres ou uuid) et un
  avertissement `projects.scraping_suspected` est écrit une fois par jour au-delà de
  `security.scraping.projects_daily_threshold` (300). Filtres : `category` (sous-catégories incluses),
  `country`, `remote_ok` (`true`/`false`/`1`/`0`), `budget_min`, `budget_max` (fourchettes qui se
  recouvrent ; projets sans budget exclus) et `currency` (EUR par défaut dès qu'un filtre de budget est
  présent). Tri par `published_at` décroissant. Brouillon, clos, expiré ou inconnu : 404.
- **Vitrine publique** (décisions 71 et 80) : `GET /public/projects` (filtres `category` avec
  sous-catégories, `country` ; tri par `published_at`) et `GET /public/projects/{uuid}`, sans
  compte, limiteur par IP `public-projects` (30/min, 1 000/j). `PublicProjectResource` :
  `uuid`, `title`, `description` (publique depuis la décision 80 ; le porteur est averti de ne
  pas y écrire ses coordonnées), `category`, `budget_min`, `budget_max`, `currency`, `country`,
  `remote_ok`, `published_at` seulement (jamais la ville, la date de début ni rien du
  porteur). Projets `browsable()` sortis de la fenêtre d'accès anticipé. Testé par
  `tests/Feature/Directory/PublicProjectShowcaseTest.php`.
- **Confidentialité** : `PublishedProjectResource` n'expose ni l'email, ni le nom, ni l'identifiant du
  porteur de projet, seulement `owner.account_type` (particulier ou entrepreneur) et `owner.country`
  (`users.country`). Le contact passera par la mise en relation (à venir).
- La suppression d'un compte supprime ses projets (clé étrangère en cascade, aucun fichier rattaché).
  `DeleteProject` reste le point d'entrée unique pour supprimer un projet : il fait d'abord expirer les
  propositions en attente sur ce projet (comme `CloseProject`).

## Module Directory (mise en relation et messagerie)

Toutes les routes : `auth:sanctum` et `verified` (403 sans email vérifié). Seuls les deux
participants voient une demande ou une conversation : **tout autre utilisateur reçoit 404**, quel que
soit son rôle, admin compris (`MatchRequestPolicy`, `ConversationPolicy`, vérifiées avant la
validation). Un participant qui tente l'action de l'autre reçoit 403 ; un état incompatible, 409.

- **Demandes** (`MatchRequest`, table `match_requests`, uuid public) :
  - `proposal` : `POST /projects/{uuid}/proposals` (prestataire, mêmes conditions que la consultation
    des projets, `ProjectPolicy::propose`) : `message`, `price_amount` et `price_currency` facultatifs
    (devise exigée avec un montant). Projet non visible : 404.
  - `contact` : `POST /providers/{slug}/contact` (particulier ou entrepreneur) : `message` et `project`
    facultatif (uuid d'un de SES projets publiés, 422 sinon). Profil non publié : 404.
  - Message de 20 à 5000 caractères, texte brut (`NoHtml`, `NoInvisibleCharacters`).
  - Statuts `MatchRequestStatus` : `pending` → `accepted` | `declined` | `withdrawn` | `expired` ; les
    autres sont définitifs (409, sous verrou : `PendingMatchRequest`). `accept`, `decline`, `report`,
    `block` : destinataire ; `withdraw` : auteur.
  - **Au plus une demande active** (`pending` ou `accepted`) par auteur et projet (proposition) ou par
    auteur et prestataire (contact) : colonne `active_key` avec index unique (409), libérée au refus, au
    retrait et à l'expiration. Au plus `MatchRequest::MAX_PENDING_SENT` (30) demandes en attente
    envoyées par utilisateur (409).
  - Expiration (`match-requests:expire`, 02:30) : en attente depuis 30 jours, ou proposition sur un
    projet qui n'est plus visible. `CloseProject` et `DeleteProject` font expirer tout de suite les
    propositions en attente du projet. Les contacts qui citent un projet ne sont pas concernés.
  - `GET /me/match-requests?box=received|sent` (filtres `status`, `type`) et `GET /me/match-requests/{uuid}`.
- **Confidentialité** (`ViewerContext`, `MatchRequestResource`, `ConversationResource`) : aucun id
  interne, aucun email, aucun téléphone. L'interlocuteur (`counterpart`) est un prestataire (résumé
  public via `GetProviderSummaries`, jamais son nom d'utilisateur) ou un porteur de projet (type de
  compte et pays ; **son nom n'apparaît qu'une fois la demande acceptée**). Compte supprimé :
  `{"deleted": true}`. Un message n'indique son expéditeur que par `mine`.
- **Messagerie** : l'acceptation ouvre une `Conversation` à deux `ConversationParticipant`
  (`match_request_id` unique). `GET /me/conversations` (avec `unread_count`, `can_send`,
  `blocked_by_me`), `GET /me/conversations/unread` (compteur global léger pour l'interrogation
  régulière), `GET /me/conversations/{uuid}/messages` (paginé du plus récent au plus ancien ; avec
  `after=<uuid>` : seulement les nouveaux, du plus ancien au plus récent, 100 au plus, `meta.has_more`),
  `POST …/messages` (`body`, 5000 caractères, texte brut), `POST …/read` (`up_to` facultatif ; le
  marqueur n'avance jamais en arrière). Envoyer un message vaut lecture des messages précédents.
- **Blocage** (`UserBlock`) : entre deux utilisateurs, dans les deux sens, toutes conversations et
  toutes nouvelles demandes (403). `POST|DELETE /me/conversations/{uuid}/block` (seul celui qui a
  bloqué débloque) et `POST /me/match-requests/{uuid}/block` (le destinataire bloque l'auteur ; une
  demande en attente est refusée sans email). L'historique reste lisible.
- **Signalements** : `POST /me/match-requests/{uuid}/report` (destinataire) et
  `POST /me/conversations/{uuid}/messages/{messageUuid}/report` (message de l'interlocuteur, 422 pour
  le sien), motif `ReportReason` (`spam`, `harassment`, `scam`, `inappropriate`, `off_platform`,
  `other`, rattaché à une catégorie par `ReportReason::category()`) et `comment` facultatif
  (1000 caractères). `ReportContent` délègue à l'Action publique `Moderation\Actions\SubmitNotice`
  (table `moderation_notices` ; l'ancienne table `abuse_reports` a été reprise par une migration) :
  copie du contenu, une fois par contenu en cours d'examen et par utilisateur (409). Le traitement
  se fait dans `/admin/moderation`. Un message ou une demande masqués gardent leur place avec
  `body` ou `message` à null.
- **Emails** (`Notifications/`, file d'attente après commit, langue `users.locale`) : demande reçue,
  acceptée, refusée ; nouveau message au plus une fois toutes les 15 minutes par conversation et par
  destinataire (`last_notified_at`, UPDATE conditionnel), abandonné s'il a été lu entre-temps. **Ni
  contenu de message, ni nom, ni titre d'un projet d'autrui** ; seule exception : la proposition reçue
  cite au porteur de projet le titre de SON projet. `lang/fr.json` traduit le gabarit de Laravel.
- **Suppression de compte** (`ForgetUserMatching`, sur `AccountDeleting`) : demandes non acceptées
  supprimées ; conversations conservées pour l'autre participant (identifiants à null, lecture seule,
  `orphaned_at`), supprimées tout de suite si les deux comptes sont supprimés ; blocages supprimés ;
  signalements conservés et anonymisés (module Moderation).
- **Minimisation** (`matching:prune`, 03:30, durées en mois dans `config/security.php`, `retention`) :
  conversations orphelines (12), demandes refusées, retirées ou expirées (12). Les signalements
  relèvent de `moderation:prune`.
- **Actions publiques** (lecture seule, pour d'autres modules) : `IsBlockedBetween` (blocage dans un
  sens ou dans l'autre), `GetUserModerationSignals` (nombre de signalements reçus par ses contenus,
  via `Moderation\Actions\CountNoticesAgainst`, et de blocages subis, sans aucun détail),
  `GetMatchRequestContext` (contexte d'une demande vue par un participant : prestataire, client,
  rôle du client, projet, acceptée, blocage ; module Marketplace), `FindOwnProject` (projet de
  l'utilisateur par son uuid), `DescribeOwnProject` (résumé d'un projet de l'utilisateur par son
  identifiant interne, module Workspaces) et `CreateDraftProject` (brouillon créé pour
  l'utilisateur avec les règles de `POST /me/projects`, jamais publié ; module Workspaces).
- **Projets masqués par la modération** : scope `browsable()` (en ligne et non masqué) pour la
  consultation et les propositions ; un projet masqué compte toujours dans le plafond de
  publication et ses propositions en attente expirent (`match-requests:expire`).
- **Accès anticipé** (décisions 33 et 44, `Support/EarlyAccess`) : pendant
  `directory.early_access_hours` (24, `DIRECTORY_EARLY_ACCESS_HOURS`, `config/directory.php`)
  après sa publication ou sa republication, un projet n'est visible que des prestataires qui ont
  `directory.project_early_access` (Premium) : liste, détail, propositions et signalement (404).
  L'ordre des résultats est le même pour tous. Le porteur voit `early_access_ends_at`.
  `phpunit.xml` désactive la fenêtre (0) pour le reste de la suite ;
  `tests/Feature/Directory/EarlyAccessTest.php` la remet à 24.

## Module Events (agenda d'événements)

Agenda public d'événements pour entrepreneurs (ateliers, conférences, webinaires, networking,
salons) avec inscription des participants. **Principe central : aucun événement proposé par un
utilisateur n'apparaît dans l'agenda public sans la validation d'un admin.** Réglages métier dans
`config/events.php`, limites et durées de conservation dans `config/security.php`.

- **Organisateurs** (`EventPolicy`, `Support/Organizers`) : prestataires avec un profil publié et au
  moins une catégorie (Action publique `HasPublishedProviderProfile`), entrepreneurs, rédacteurs et
  admins, avec un email vérifié. L'événement d'un autre utilisateur donne **404**, admin compris :
  la modération passe uniquement par `/admin/…`.
- **Statuts** (`EventStatus`) : `draft` → `pending_review` → `published` → `past` (tâche
  `events:close-past`) ; `pending_review` → `rejected` (motif obligatoire ; corrigé puis soumis à
  nouveau) ; `published` → `cancelled`. Seul l'admin publie sans validation (`POST …/submit` répond
  200 au lieu de 202), et seulement si sa session a passé la 2FA (403 sinon), avec une trace d'audit.
  Plafonds : 3 événements en attente et 20 publiés à venir par organisateur (409).
- **Contenu** (`Support/EventContent`, `Support/EventRules`) : titre, description (texte brut), type
  (`workshop`, `conference`, `webinar`, `networking`, `trade_show`, `other`), format (`in_person`,
  `online`, `hybrid`), langue (`LanguageCode`), 0 à 3 catégories, lieu (`address_visibility` :
  `public` ou `registrants`), lien de connexion (`online_url`, **chiffré en base**, jamais public),
  site (`external_url`), gratuit ou payant (`ticket_url`, billetterie externe sans inscription
  interne), capacité facultative, couverture (`event_cover`). Dates **saisies en heure locale**
  (`starts_at: "2026-12-03T18:30"`) avec le fuseau IANA (`timezone`), converties une fois en UTC ;
  une heure inexistante (passage à l'heure d'été) donne 422. Les réponses donnent les dates en UTC,
  le fuseau et un objet `local`. Les champs incompatibles avec le format ou la gratuité sont vidés.
  `organizer_name` est obligatoire sauf pour un prestataire (présenté par son profil, champ interdit).
  Les champs fixés par la plateforme (`status`, `slug`, `version`, `user_id`, dates du cycle de
  vie…) sont **refusés** (`prohibited`, 422).
- **Révisions** (`EventRevision`, `SubmitRevision`) : modifier un événement publié (non-admin) crée
  ou remplace **une révision en attente** (202) contenant la version complète proposée (JSON chiffré)
  et `changed_fields`. Le public voit la version validée jusqu'à l'accord d'un admin. Tous les champs
  sont concernés (lien de connexion, liens, adresse, capacité…). Une couverture proposée est rattachée
  à la révision. Un événement en attente se retire (`POST …/withdraw`) avant d'être modifié (409
  sinon) ; un événement commencé n'est plus modifiable. L'annulation reste immédiate.
- **Versions** : `events.version` et `event_revisions.version` augmentent à chaque modification ;
  l'admin envoie la `version` qu'il a lue, sinon 409 (pas de validation d'un contenu non relu).
- **Modération** (`/admin/…`, `ModerationController`) : middleware du noyau `EnsureModerator` (rôle
  admin, sinon **404** ; 2FA confirmée sur le compte **et** session ouverte avec un code 2FA, sinon
  **403**). File d'attente (`GET /admin/events/queue`, événements, révisions et annonces du plus
  ancien au plus récent, `kind`, `meta.counts`), détail avec le contenu complet (lien de connexion
  en clair seulement pendant la modération), liens et domaines signalés (`punycode`,
  `previously_approved`) et historique de l'organisateur (`OrganizerHistory`, avec l'Action publique
  `Directory\Actions\GetUserModerationSignals`). Validation, refus avec motif (10 à 1000 caractères),
  annulation par un admin (motif). Chaque décision : journal d'audit en base (`AdminAudit`), canal
  security, limiteur `event-moderation`.
- **Récapitulatif aux admins** (`events:notify-moderators`, toutes les 5 minutes) : au plus un email
  toutes les 30 minutes, seulement si un élément est arrivé depuis le précédent, sans contenu.
- **Agenda public** : `GET /events` (publiés et pas terminés, tri par début ; filtres `from`, `to`
  (journées UTC, 366 jours au plus), `type`, `format`, `country`, `city`, `category` (sous-catégories
  comprises), `language`, `free`, `q` (`Like` sur titre, description, ville)), `GET /events/{slug}`
  (publié ou passé, 404 sinon) et `GET /events/{slug}/calendar.ics` (sans lien de connexion, adresse
  masquée si réservée aux inscrits). Slug calculé à la **première validation**, puis stable.
  L'organisateur est présenté par son profil prestataire (`GetProviderSummaries`) ou par
  `organizer_name`, jamais par son nom d'utilisateur. `registration.privacy` annonce avant
  l'inscription que le nom et le type de compte de l'inscrit seront visibles par l'organisateur.
- **Inscriptions** (`RegisterForEvent`) : événement gratuit, publié et pas commencé ; capacité et
  plafond `events.max_registrations` (300) respectés sous verrou de la ligne de l'événement, index
  unique (`event_id`, `user_id`) ; pas de liste d'attente (409 complet). L'organisateur et tout
  utilisateur bloqué dans un sens ou dans l'autre (Action publique `Directory\Actions\IsBlockedBetween`)
  reçoivent 403. Désinscription avant le début. L'inscrit trouve le lien de connexion et l'adresse
  complète dans `GET /me/event-registrations/{slug}` et son `.ics`, **jamais dans un email**.
- **Organisateur et inscrits** : liste des inscrits (nom et type de compte uniquement) ; retrait
  d'un inscrit (`DELETE /me/events/{uuid}/registrations/{registrationUuid}`, statut `removed`, email
  neutre sans motif, réinscription impossible, `events.registration_removed`) ; annonces
  (`POST /me/events/{uuid}/announcements`, 20 à 2000 caractères) : l'email ne contient pas le texte,
  lu sur la page de l'événement ; une annonce avec un lien ou un nom de domaine attend la validation
  d'un admin. 1 annonce par 24 h, 5 par événement.
- **Liens** : règles du noyau `SafeExternalUrl` et `SafeLinksInText` (https, ni IP, ni hôte local,
  ni raccourcisseur, ni domaine de `DENIED_LINK_DOMAINS`), aussi sur les liens écrits dans le texte.
  Le frontend ne transforme jamais une URL de la description ou d'une annonce en lien cliquable.
- **Emails** (`EventNotification`, textes dans `EventNotice`, file d'attente après commit, langue du
  destinataire) : confirmation, rappel 24 h avant, modification importante validée (date, lieu,
  lien), annulation, annonce, retrait (participants) ; validation ou refus avec motif (organisateur).
  Ni lien de connexion, ni texte d'annonce, ni nom ou email d'un autre utilisateur.
- **Tâches** : `events:send-reminders` (15 min, sans doublon : `UPDATE` conditionnel sur
  `reminder_sent_at`), `events:close-past` (chaque heure), `events:notify-moderators` (5 min),
  `events:prune` (04:00).
- **Suppression de compte** (`ForgetUserEvents`, sur `AccountDeleting`) : inscriptions supprimées
  et places libérées ; événements publiés à venir annulés (`cancelled_by = system`), inscrits
  prévenus, puis détachés (`user_id` et `organizer_name` à null) ; autres événements supprimés.
- **Minimisation** (`events:prune`, `config/security.php`, `retention`) : inscriptions et annonces
  12 mois après la fin, révisions décidées 6 mois, événements annulés ou détachés 12 mois après la fin.

## Module Blog

Blog éditorial et articles d'auteurs extérieurs. **Principe central : aucun article d'un
prestataire ou d'un entrepreneur (premium ou non) n'est public sans la validation de l'équipe ;
une modification d'un article publié est une révision qui ne remplace la version publiée qu'après
validation.** Réglages métier dans `config/blog.php`, limites et durées dans `config/security.php`.

- **Auteurs** (`ArticlePolicy`, `Support/Authors`), email vérifié : rédacteurs et admins
  (l'équipe, `author_kind = editorial`), prestataires avec un profil publié et au moins une
  catégorie (`provider`), entrepreneurs (`entrepreneur`). Le type d'auteur est fixé à la
  création. L'article d'un autre utilisateur donne **404**, modérateurs compris.
- **Statuts** (`ArticleStatus`) : `draft` → `pending_review` → `published` ; `pending_review` →
  `rejected` (motif) ou `draft` (retrait) ; `published` → `unpublished` (dépublication par la
  modération, motif). Refusé ou dépublié : modifiable puis soumis de nouveau (au plus
  `max_resubmissions` = 5 fois, 409). En attente : 409 sur modification. L'équipe publie et
  modifie ses propres articles **directement**, avec la 2FA de session (403 sinon) et une trace
  d'audit ; elle n'a ni quota ni révision.
- **Quota gratuit** (`Support/ArticleQuota`) : `blog.free_articles_quota` (2) articles **soumis**
  par prestataire ou entrepreneur, comptés **à la première soumission de chaque article** dans
  `article_quota_entries` (une fois pour toutes : refus, retrait, suppression ne libèrent rien ;
  les soumissions faites pendant une période premium comptent aussi). `free_quota_period_months`
  (`null` : à vie). Au-delà, sans premium : **403 `{"message", "code": "premium_required",
  "quota": {"limit", "used", "remaining"}}`** (`Exceptions\PremiumRequired`, code stable pour le
  frontend). Premium : pas de quota. Pour tous les auteurs extérieurs : 3 articles en attente au
  plus (`max_pending_per_author`, 409) ; pour tous : 20 brouillons (409). Fin du premium : les
  articles publiés restent en ligne.
- **Contenu** (`Support/ArticleRules`, `ArticleContent`) : `title` (10-160), `excerpt` (chapô,
  50-300), `body`, `language` (liste `blog.languages`, `fr` et `en`), `categories` (0 à 3 slugs
  `ServiceCategory` actifs), `cover` (média `article_cover`), `author_name` (entrepreneur seul,
  `prohibited` sinon), `seo_title`, `seo_description`. Soumission : chapô, corps de 200 mots au
  moins (`min_words`), une catégorie, nom d'auteur pour un entrepreneur (422 sinon). Champs fixés
  par la plateforme : `prohibited` (422). Temps de lecture `ceil(mots / 230)`. Pas de mots-clés
  ni de commentaires en v1.
- **Corps** (`Support/ArticleBody`) : document JSON `{"version": 1, "blocks": [...]}` à liste
  fermée, **jamais de HTML stocké ni renvoyé**. Blocs `paragraph`, `heading` (niveaux 2 et 3,
  sans lien), `list` (`bullet`/`ordered`, sans imbrication), `quote` (`attribution`), `image`
  (`media` = uuid d'un média `article_image` de l'auteur, `alt` obligatoire, `caption` ; jamais
  d'URL), `divider`. Segment : `{"text", "marks": ["bold", "italic"], "link": "https://…"}`.
  Clé, type ou marque inconnus : 422 avec l'emplacement (« Bloc 3, segment 2 : … »). Texte :
  `NoHtml`, `NoInvisibleCharacters`, `SafeLinksInText` ; liens : `ExternalLink` (https, ni
  `javascript:` ni `data:`). Plafonds `blog.body` (300 blocs, 40 000 caractères, 10 images,
  30 liens…), requête limitée à 256 Ko (413). Le serveur enregistre le document qu'il a
  **reconstruit**, jamais l'entrée brute. Images du corps : table `article_media` (article ou
  révision), toujours supprimées par `MediaLibrary`.
- **Liens et référencement** (`Support/ArticleBodyPresenter`) : chaque lien est renvoyé en
  `{"href", "rel"}` ; le frontend reprend le `rel` tel quel (`target="_blank"`), sans
  `dangerouslySetInnerHTML`. **Tout auteur extérieur à l'équipe, premium compris, reçoit
  `rel="nofollow ugc noopener noreferrer"`** ; seule la rédaction a des liens suivis
  (`noopener noreferrer`). **Ce n'est jamais un avantage premium possible** : un auteur premium
  paie pour publier, ses liens sont des liens payants pour les moteurs de recherche, et un lien
  payant suivi expose le site à une pénalité. Le `rel` ne dépend que de `author_kind`.
- **Révisions** (`ArticleRevision`, `SubmitRevision`) : une seule en attente par article,
  remplacée par chaque modification (version + 1), version complète proposée, images et
  couverture proposées rattachées à la révision ; limite `article_revisions` (dans l'Action).
- **Modération** (`/admin/…`, `ModerationController`) : **rédacteurs et admins** avec 2FA confirmée
  et session ouverte avec un code (`EnsureModerator:admin,redacteur` ; 404 pour les autres rôles,
  403 sans 2FA). **Personne ne modère son propre article ni sa propre révision** (403,
  `blog.self_moderation_denied`). Dépublication d'un article de la rédaction : **admin
  seulement**. File (`GET /admin/blog/queue`, `own`, `meta.counts`), détail avec contenu complet,
  liens et domaines signalés (`ArticleLinks`), historique de l'auteur (`AuthorHistory` : premium,
  articles par statut, signaux de la messagerie), décisions passées. Validation, refus (motif
  10-1000), dépublication (motif), révisions ; `version` lue obligatoire (409). Journal d'audit
  (`blog.article.*`, `blog.revision.*`), canal security, limiteur `blog-moderation`.
- **Récapitulatif** (`blog:notify-moderators`, toutes les 5 minutes) : au plus un email toutes les
  30 minutes aux rédacteurs et admins avec 2FA, seulement si du nouveau est arrivé, sans contenu.
- **Lecture publique** : `GET /articles` (publiés, du plus récent ; filtres `category`
  (sous-catégories comprises), `language`, `author` (slug de prestataire ou `editorial`), `q`
  (`Like` sur titre et chapô)), `GET /articles/{slug}` (corps, `seo`, `related` ; 404 si non
  publié, **410** si supprimé), `GET /providers/{slug}/articles`, `GET /articles/sitemap` (`slug`,
  `language`, `updated_at`, pages de 1 000, `Cache-Control` 1 h). Slug calculé à la **première
  publication** puis stable ; un slug retiré (`article_retired_slugs`) n'est jamais repris ;
  `sitemap` est réservé. Auteur (`AuthorDirectory`) : profil public du prestataire
  (`GetProviderSummaries`), nom d'auteur de l'entrepreneur, ou nom de la rédaction
  (`blog.editorial_name`) ; jamais d'email, de nom d'utilisateur ni d'id.
- **Emails** (`ArticleNotification`, textes dans `ArticleNotice`, file d'attente après commit,
  langue de l'auteur) : validé, refusé (motif), dépublié (motif), révision validée ou refusée
  (motif). Le titre de son propre article seulement ; jamais le contenu ni le nom d'un modérateur.
- **Suppression de compte** (`ForgetUserArticles`, sur `AccountDeleting`) : articles des
  prestataires et entrepreneurs **supprimés**, publiés compris (slug mis en retraite, 410) ;
  articles de l'équipe **détachés** (`user_id` à null) et conservés (leurs images, propriété du
  compte, disparaissent ; le corps présenté omet les images absentes).
- **Minimisation** (`blog:prune`, 05:00, `config/security.php`, `retention`) : révisions décidées
  6 mois, brouillons jamais soumis 12 mois sans modification, articles refusés ou dépubliés
  12 mois sans modification, slugs retirés 24 mois.

## Module Compliance (suivi administratif)

Suivi administratif des prestataires et des entrepreneurs : profil d'entreprise, calendrier
d'échéances, rappels, tâches et parcours, chiffre d'affaires et seuils, estimation (premium),
abonnement au calendrier, export. Partie 2 (à venir, dans la V1) : coffre-fort de documents privé.
**Principe central : les règles fiscales et sociales (statuts, échéances, seuils, taux) ne sont
JAMAIS écrites dans le code ; ce sont des données vérifiées par un professionnel, et une règle non
vérifiée n'est jamais montrée.** **L'outil est une aide à l'organisation et ne remplace pas un
expert-comptable** : chaque réponse porte le champ racine `disclaimer` (`{"text", "version"}`,
`Support/Disclaimer`), repris en pied de chaque email et dans l'abonnement `.ics` ; le frontend
l'affiche sur chaque écran. Réglages dans `config/compliance.php` (aucune valeur fiscale), limites
et durées dans `config/security.php`.

- **Règles** (`ComplianceRule`, table `compliance_rules`) : par pays, `kind` (`legal_form`,
  `deadline`, `threshold`, `rate`, `journey`, `vat_rate`), `code` stable entre versions et années
  (`fr.vat.franchise.services.base`), `valid_from`/`valid_until`, `status` (`draft`, `verified`,
  `retired`), `version`, `title`/`summary` traduits, `conditions` et `payload` à **liste fermée**
  de clés reconstruites par le serveur (`Support/RuleContent`), `source_url`/`source_label`,
  `last_verified_at`, `verification_note`, `internal_notes`. Montants en centimes, taux en points
  de base (2120 = 21,20 %), toujours entiers. Un brouillon peut laisser une valeur à `null`
  (« à compléter ») ; la vérification exige un contenu complet et une source (422 avec la liste
  `missing_fields`). Conditions : statut, activités (`ActivityType`), régime de TVA (`VatRegime`),
  options (`ComplianceOption`) ; une liste vide veut dire « toutes ». Seules les règles
  `usable()` (vérifiées, et jamais `is_demo` hors du poste local) sortent vers les utilisateurs
  (`Support/RuleCatalog`). `tests/Arch/ComplianceNoFiscalValuesTest.php` refuse tout décimal ou
  grand nombre dans le code du module.
- **Administration** (`/admin/compliance/rules`, `RuleAdminController`, **admin seul** avec 2FA de
  session, `EnsureModerator` ; les admins n'ont aucun accès aux données des entreprises) : création
  en brouillon, modification d'un brouillon (version lue, 409 sinon), `POST …/revise` (une règle
  vérifiée ne se modifie jamais sur place : nouveau brouillon `replaces_rule_id`, l'ancienne reste
  en vigueur jusqu'à la vérification du brouillon, qui la retire), `POST …/verify`,
  `POST …/retire` (motif), suppression d'un brouillon. Pas deux versions vérifiées d'un même code
  sur des périodes qui se chevauchent (409). Journal d'audit `compliance.rule.*` (motifs et notes),
  canal security sans motif ni note, limiteur `compliance-rules-admin`. Vérifier ou retirer une
  règle d'échéance recalcule en file d'attente les calendriers du pays (`RefreshCountryCalendars`).
- **Vérification humaine** : une règle est vérifiée par un **professionnel** (expert-comptable
  externe, sans compte admin) ; l'admin enregistre la vérification avec une **`verification_note`
  obligatoire** d'au moins `compliance.verification_note_min_length` (30) caractères, qui dit
  **qui** a vérifié (nom du cabinet ou du professionnel), **quand**, et **sur quel document ou
  quelle source** (422 sinon). Elle est gardée sur la règle et dans le journal d'audit.
- **Quatre yeux** (`compliance.require_distinct_verifier`, `COMPLIANCE_REQUIRE_DISTINCT_VERIFIER`,
  **false par défaut** tant que l'équipe ne compte qu'un admin) : à `true`, l'admin qui a modifié
  une règle en dernier ne peut pas la vérifier (403, `compliance.rule_self_verification_denied`).
  Pour l'activer : `COMPLIANCE_REQUIRE_DISTINCT_VERIFIER=true` dans `.env`, puis
  `php artisan config:cache`. `security:check-config` avertit si le réglage reste à false alors que
  deux admins au moins ont la 2FA.
- **Brouillons à importer** : `php artisan compliance:import-rules <fichier.json> [--dry-run]`
  (`Support/RuleImporter`) crée **uniquement des brouillons**, validés comme par l'API, tout ou
  rien ; une règle déjà présente (même code et même `valid_from`) est ignorée, une règle vérifiée
  n'est jamais modifiée. Utilisable en production pour la relecture par l'expert-comptable ;
  **jamais appelée par `ReferenceDataSeeder`**. Fichier livré :
  `database/data/compliance/fr-2027.draft.json` (38 règles 2027). Chaque valeur remplie y cite
  « lue sur <URL> le <date> » ; sinon elle est à `null` avec « à compléter : <source> »
  (`RuleImportTest` vérifie ces deux points). **N'y écris jamais une valeur de mémoire.**
- **Mise à jour annuelle** : `GET /admin/compliance/coverage?year=` (codes sans version vérifiée
  pour l'année, règles sans date de fin vérifiées avant le 1er septembre, brouillons, pays encore
  proposé) ; `compliance:check-rule-coverage` envoie un récapitulatif aux admins avec 2FA, du
  1er octobre au 30 novembre, au plus une fois par semaine.
- **Accès** (`CompanyPolicy`) : prestataires et entrepreneurs avec un email vérifié (403 sinon,
  profil publié non requis). **L'entreprise d'un autre utilisateur et toutes ses données donnent
  404, admins compris.** `tests/Feature/Security/ComplianceAuthorizationTest.php` teste CHAQUE
  route du module (lue dans le routeur) contre chaque rôle et un autre utilisateur.
- **Questionnaire** : `GET /compliance/countries` (pays de `config/compliance.php` ayant un statut
  vérifié couvrant toute l'année), `GET /compliance/countries/{cc}/questionnaire`,
  `POST /me/compliance/companies` (nom, pays, statut, 1 ou 2 activités, régime de TVA, date de
  création, clôture `MM-DD`, options, SIREN/SIRET, TVA), vérifié contre les règles du pays
  (`Support/CompanyProfile`, 422 sur le champ). Le pays ne change jamais (422).
- **Calendrier** (`Support/CalendarGenerator`, `DeadlineSchedule`) : échéances des règles vérifiées
  sur 15 mois (`yearly`, `monthly`, `quarterly`, `fixed_dates`, `after_creation`,
  `after_fiscal_year_end` ; jour ramené au dernier jour du mois ; `skip_creation_year`), clé
  d'occurrence par période (une date déplacée par une nouvelle version déplace l'échéance).
  Recalcul idempotent : **une échéance faite ou ignorée n'est jamais modifiée** (seulement
  `obsolete_at` si sa règle ne s'applique plus), une échéance passée reste, une échéance
  personnelle n'est jamais touchée. `compliance:extend-calendars` fait avancer la fenêtre.
  **Pas de report automatique** d'une échéance qui tombe un week-end ou un jour férié : la date
  vient de la règle.
- **Échéances et tâches** : `/me/compliance/deadlines` (toutes les entreprises, 400 jours au plus,
  `status=overdue`), `/me/compliance/companies/{company}/deadlines` (personnelles : 50 à venir au
  plus ; issues d'une règle : notes seulement, à ignorer plutôt qu'à supprimer),
  `…/complete|skip|reopen`, `…/tasks` (issues des règles : à cocher seulement), parcours types
  (règles `journey`, gratuit : un parcours actif par entreprise). Une tâche qui demande un
  professionnel porte une `service_category` : le frontend propose des prestataires avec
  `GET /providers?category=<slug>&country=<cc>`, sans couplage entre modules.
- **Chiffre d'affaires et seuils** : `PUT …/revenue/{AAAA-MM}` (`{"lines": {"<activité>": <centimes>}}`,
  entiers JSON stricts de 0 à `compliance.revenue.max_amount`, du mois de création au mois en
  cours), `GET …/revenue`, `…/revenue.csv`, `…/thresholds` (`Support/ThresholdReport` : seuil
  proratisé au jour l'année de création si la règle le prévoit, ratio en points de base,
  projection linéaire sur les mois complets, paliers ; **arrondis par défaut, annoncés par
  `"rounding": "floor"`**), `…/estimate` (premium, `Support/ContributionEstimate`, taux vérifiés
  seulement, `is_estimate`, `available: false` sans taux pour le profil). Paliers atteints et
  dépassement projeté enregistrés une fois par année (`compliance_threshold_alerts`). Limite
  connue : les seuils sont comparés au cumul de l'année en cours (les seuils appréciés sur N-1 ou
  N-2 sont signalés dans les notes des brouillons).
- **Gratuit et premium** (`Support/Entitlements`, Action publique `Accounts\Actions\IsPremium`) :
  gratuit = 1 entreprise, un délai de rappel parmi 3, 7 ou 14 jours, paliers 80 % et 100 %,
  1 parcours actif, abonnement `.ics`, export ; premium = 5 entreprises, jusqu'à 3 délais, paliers
  personnalisés, parcours illimités, estimation. Refus : **403
  `{"message", "code": "premium_required", "feature"}`** (`Exceptions/PremiumFeatureRequired`,
  même code que le Blog ; `feature` : `multiple_companies`, `estimate`, `custom_reminders`,
  `custom_threshold_levels`, `multiple_journeys`). Fin du premium : rien n'est supprimé ; une
  entreprise reste active (choisie par `POST …/activate`, sinon la plus ancienne), les autres sont
  verrouillées (`locked` : lecture, export, suppression) ; les réglages premium restent enregistrés
  mais les valeurs gratuites s'appliquent (`effective_*`).
- **Rappels** (`compliance:send-reminders`, `SendDigests`) : **un email par utilisateur et par jour
  au plus** (quota Resend), réservé avant l'envoi (`compliance_digests`,
  `compliance_reminder_deliveries`, index uniques) ; quotidien (délais) ou hebdomadaire (lundi,
  14 jours) ; paliers de seuil, calendrier modifié, annonce de la purge annuelle le 1er décembre.
  Titres, dates et pourcentages seulement, chiffré dans la file (`ShouldBeEncrypted`).
- **Abonnement au calendrier** (gratuit) : `POST|GET|DELETE /me/compliance/calendar-feed` (URL
  montrée une seule fois) ; `GET /compliance/calendar/{token}.ics` **public** : jeton de
  56 caractères (sélecteur de 16 + secret de 40), seule l'empreinte HMAC-SHA256 (`APP_KEY`) du
  secret est stockée, comparaison en temps constant (`Support/CalendarFeedToken`) ; titres et
  dates en journée entière, jamais de montant ni de note ; entreprises actives seulement.
- **Données chiffrées en base** (cast `encrypted` et `Support/EncryptedInteger`) : nom de
  l'entreprise, SIREN/SIRET, numéro de TVA, montants du chiffre d'affaires, titres et notes saisis.
  Ces colonnes ne sont ni indexées ni utilisées dans une requête SQL. **Elles dépendent de
  `APP_KEY`** : voir SECURITY.md (sauvegarde, rotation avec `APP_PREVIOUS_KEYS`).
- **Export** : `GET /me/compliance/export` (JSON complet, portabilité RGPD ; section `documents`
  réservée au coffre-fort).
- **Suppression de compte** (`ForgetUserCompliance`, sur `AccountDeleting`) : tout est supprimé.
- **Minimisation** (`compliance:prune`, 05:30, `config/security.php`, `retention`) : échéances
  passées depuis 36 mois, chiffre d'affaires au-delà de 6 années civiles, alertes et parcours
  terminés depuis 36 mois ; durées et prochaine purge annoncées par `GET /me/compliance`.
- **Coffre-fort (partie 2)** : prévoir un disque **privé** (pas la brique média, publique), une
  table rattachée à `company_id` (et facultativement à une échéance ou une tâche ;
  `expects_document` est déjà dans le contenu des règles d'échéance), la même `CompanyPolicy`, la
  suppression dans `ForgetUserCompliance` et la section `documents` de l'export.

## Module Moderation (signalements, DSA)

Signalement des contenus, décisions motivées et recours, selon le règlement (UE) 2022/2065 (DSA)
pour une petite entreprise (art. 16, 17 et 18 ; recours interne de l'art. 20 appliqué
volontairement). Décisions 21 à 31 de `docs/v1/DECISIONS.md` ; règles, textes et procédure
art. 18 : `docs/v1/moderation-rules.md` (**à faire valider par le juriste**). Réglages dans
`config/moderation.php`, limites et durées dans `config/security.php`.

- **Contenus signalables** (contrat du noyau `ModerationTarget`, `config/moderation.php`,
  `targets`) : `provider_profile` (slug), `project` (uuid, signalable par tout visiteur de la vitrine
  publique, décision 71), `event` (slug), `event_announcement` (uuid, visible des inscrits),
  `article` (slug), `offer` (slug), `message`, `match_request` et `quote` (privés : messagerie et
  page du devis seulement). Chaque adaptateur vit dans
  son module (`Support/…ModerationTarget`) : `findVisible()` (404 si le visiteur ne le voit pas),
  `inspect()`, `restrict()`, `lift()`. **Toute nouvelle page publique de contenu (offres, avis…)
  doit avoir son adaptateur** (`tests/Feature/Moderation/RestrictionSurfacesTest.php`).
- **Restriction** : réversible, sans toucher au statut ni à `updated_at` ; le contenu disparaît de
  toutes ses surfaces publiques. Profil : traité comme non publié partout (scope `published()`,
  décision 23). Projet : scope `browsable()`. Événement : agenda, fiche, `.ics`, inscriptions,
  rappels et annonces fermés, inscrits prévenus par un email neutre
  (`EventNotice::HiddenByModeration`, décision 24). Article : scope `published()`. Le propriétaire
  voit `moderation` `{restricted, decision}` dans ses propres réponses.
- **Signalement** (`POST /moderation/notices`, public, avec ou sans compte ; `SubmitNotice`) :
  type et identifiant du contenu, catégorie (liste fermée, `categories`), explication (20 à 5000),
  précision facultative, nom et email sans compte (facultatifs pour `child_sexual_abuse`, interdits
  avec un compte), `good_faith` obligatoire, champ piège `homepage`. Son propre contenu : 422 ;
  déjà en cours : 409 (`active_key`). Copie **textuelle** du contenu et empreinte de version.
  Identité, explication et copie **chiffrées** en base. Sans compte : jeton de suivi de
  48 caractères remis une fois (empreinte HMAC seulement, `TrackingToken`), renouvelé à chaque
  email, porté dans le fragment de l'URL du frontend ; accusé de réception par email.
  `POST /moderation/notices/{uuid}/lookup` (jeton dans le corps, 404 sinon). Avec un compte :
  `GET /me/moderation/notices` et `/{uuid}`.
- **Urgences** (décision 31) : `child_sexual_abuse` et `violence_terrorism` : objectif 24 h
  (`processing_hours`, 7 jours pour les autres), en tête de file, **alerte immédiate**
  (`UrgentNoticeAlert`) aux admins avec 2FA, sans aucun contenu, une fois par contenu en cours
  d'examen. **Aucune image** d'un contenu signalé comme abus sexuels sur mineurs n'est réaffichée
  dans l'administration (`images_withheld`).
- **Administration** (`/admin/moderation`, **admins seulement**, `EnsureModerator`, 2FA de
  session ; rédacteurs : 404) : `GET queue` (contenus signalés, urgents puis objectif le plus
  proche, `overdue`, `meta.counts`), `GET targets/{type}/{ref}` (contenu actuel et `version`,
  signalements avec leur copie, signalant `account`/`guest` et nom sans compte, **jamais l'email** ;
  auteur : rôles, ancienneté, signalements et restrictions ; consultation inscrite au journal
  d'audit), `POST targets/{type}/{ref}/decisions`, `GET decisions/{uuid}`,
  `POST decisions/{uuid}/lift` (motif), `POST notices/{uuid}/referral` (trace d'une transmission
  aux autorités, art. 18 : `pharos`, `police`, `gendarmerie`, `europol`, `other` ; rien n'est
  envoyé automatiquement), `GET appeals`, `GET appeals/{uuid}`, `POST appeals/{uuid}/decide`.
  Limiteur `moderation-admin`.
- **Décision** (`DecideOnContent`, exposé des motifs art. 17 §3) : `measure`
  (`restrict_visibility` ou `no_action`), `ground` (`illegal` + `legal_reference`, ou `terms` +
  `terms_clause`), `category`, `facts` (30 à 3000), `correction_hint` (ce que l'auteur peut
  corriger, décision 23), `version` (empreinte lue, 409 sinon). Traite tous les signalements en
  cours du contenu. Jamais sur son propre contenu (403). « Aucune mesure » seulement sur
  signalement (`manifestly_unfounded` facultatif). Aucun moyen automatisé (`automated` false).
  Délai de recours : `appeal_window_months` (6).
- **Emails** (`ModerationNotification`, file après commit, **chiffrés dans la file**, textes dans
  `ModerationMail`) : accusé de réception et décision au signalant (sans les faits ni le titre du
  contenu), exposé des motifs et levée à l'auteur, issue d'un recours. Catégories obligatoires
  `moderation.notices` et `moderation.decisions` ; admins : `moderation.urgent_alerts`
  (obligatoire), `moderation.digest` (`moderation:notify-moderators`, au plus toutes les 30 min).
  **L'identité du signalant n'est jamais communiquée à l'auteur** (décision 29).
- **Exposés des motifs pour l'auteur** : `GET /me/moderation/decisions` et `/{uuid}` (404 pour
  tout autre utilisateur, admin compris).
- **Recours** (`SubmitAppeal`, `DecideAppeal`, décision 21) : l'auteur contre une restriction en
  vigueur (`POST /me/moderation/decisions/{uuid}/appeal`, aussi la voie du réexamen après
  correction), le signalant contre une absence de mesure (`POST /me/moderation/notices/{uuid}/appeal`
  ou `POST /moderation/notices/{uuid}/appeal` avec le jeton). Texte 30 à 5000 ; un en attente à la
  fois, `max_appeals_per_decision` (3), délai : 409. Accepté : restriction levée, ou signalement
  rouvert. Quatre yeux facultatif (`MODERATION_REQUIRE_DISTINCT_APPEAL_REVIEWER`, false par défaut).
  Limiteur `moderation-appeals`.
- **Events et Blog** : leurs décisions restrictives (refus, annulation, dépublication, refus de
  révision ou d'annonce) envoient `ContentModerated` ; `RecordModuleDecision` en tire un exposé des
  motifs non réversible (`source` `pre_publication_review` ou `own_initiative`, clause
  `pre_publication_clause`) que l'auteur conteste comme les autres. Leurs emails ajoutent le lien
  vers l'exposé des motifs (décision 26) ; rien d'autre ne change dans ces modules.
- **Points de contact** (art. 11 et 12, décision 30) : `GET /moderation/info` (public) publie
  `MODERATION_CONTACT_EMAIL`, `MODERATION_AUTHORITIES_EMAIL` (français et anglais), les catégories,
  les clauses des CGU et le délai de recours. `security:check-config` exige les deux adresses et
  une référence pour chaque clause des CGU (`terms_clauses.*.reference`) en production.
- **Suppression de compte** (`ForgetUserModeration`) : signalements et décisions conservés sans
  lien avec le compte, recours supprimés.
- **Minimisation** (`moderation:prune`, 05:45, décision 27) : identité, explication et copie
  effacées 12 mois après la clôture du signalement (`moderation_notices_months`) ; décisions,
  recours et signalements supprimés 24 mois après la fin du délai de recours ou la levée
  (`moderation_records_months`) ; une restriction en vigueur garde son exposé des motifs.
- **Non prévu en V1** : suspension de compte (lot 8, décision 28), rapports de transparence et
  base de données de la Commission (non obligatoires pour une petite entreprise).

## Module Marketplace (offres, devis, comparateur)

Offres des prestataires, devis structurés, comparateur de devis et accès anticipé (lot 3,
décisions 33 et 36 à 50). Réglages dans `config/marketplace.php` (aucun taux de TVA), limites et
durées dans `config/security.php`. Textes destinés aux utilisateurs, sources consultées et
**tableau des points à faire valider par le juriste et l'expert-comptable** :
`docs/v1/marketplace-rules.md`.

- **Identité professionnelle** (`SellerProfile`, table `marketplace_seller_profiles`, une par
  prestataire ; `GET|PUT /me/seller-profile`, prestataire seul, 403 sinon) : dénomination, forme
  juridique (`SellerLegalForm`), SIREN, registre (`rcs` + ville du greffe, ou `rne`), capital
  (exigé pour une société), numéro de TVA, adresse, téléphone, email, assurance, médiateur de la
  consommation, profession réglementée, CGV, mentions personnalisées (`quote_footer`, Premium).
  **Chiffrée en base**. En France, SIREN (clé de Luhn) et TVA (clé liée au SIREN) contrôlés dans
  leur forme seulement (`Rules/Siren`, `Rules/FrenchVatNumber`) ; l'existence sera vérifiée au
  lot 6, dont les colonnes `registration_*` existent déjà (effacées si le SIREN change).
  **Autocertification** datée et versionnée (`marketplace.self_certification`, à accepter à chaque
  enregistrement ; une nouvelle version bloque offres et devis jusqu'à l'acceptation). La
  réponse annonce `missing_for_offers`, `missing_for_quotes` (`country` hors de
  `marketplace.countries`, la France en V1) et `missing_for_consumer_quotes` (médiateur). Pas de
  traçabilité DSA art. 30 complète (dispense art. 29, décision 37).
- **Offres** (`Offer`, table `marketplace_offers`) : `/me/offers` (liste, création en brouillon,
  détail, PATCH, suppression, `publish`, `unpublish` ; prestataire ; offre d'autrui : 404).
  **Publiées sans validation préalable** (décision 39) ; publication : email vérifié et profil
  prestataire publié (403), identité professionnelle complète (**409
  `{"message", "code": "seller_profile_incomplete", "missing": [...]}`**), 20 publiées et
  30 au total (409). Slug calculé à la première publication puis stable. Prix **indicatif TTC
  saisi par le prestataire** (`pricing_mode` : `fixed`, `from`, `hourly`, `daily`, `on_quote` sans
  prix ; décision 40). Lecture publique : `GET /offers` (filtres `category` avec sous-catégories,
  `country`, `city`, `pricing_mode`, `q` ; tri par `published_at`, le même pour tous),
  `GET /offers/{slug}`, `GET /providers/{slug}/offers` (404 si le profil n'est pas publié).
  Visible si l'offre est publiée et non masquée **et** si le profil est publié et non masqué
  (scope `visible()`, Action publique `Accounts\Actions\PublishedProviderConstraint`). Auteur
  présenté par son profil public et sa **qualité déclarée** (`seller` : `declared_status`
  `professional`, dénomination, SIREN ; décision 38), jamais ses coordonnées.
- **Devis** (`Quote`, `QuoteLine`, tables `marketplace_quotes`, `marketplace_quote_lines`) :
  `/me/quotes` (`auth:sanctum` et `verified`). **Seulement dans une demande de mise en relation
  acceptée** (décision 36 ; Action publique `Directory\Actions\GetMatchRequestContext`) :
  demande inconnue 404, porteur de projet 403, demande en attente 409, blocage 403, client
  supprimé 409. Brouillon (`POST`, `PUT /me/quotes/{uuid}` représentation complète, `DELETE`) :
  titre, objet, bloc client (`name`, `company_name`, `registration_number`, `address`,
  `execution_place` obligatoire), validité (1 à 180 jours, 30 par défaut), date de début, durée,
  conditions de paiement, acompte ou arrhes (`DepositKind`), devis gratuit ou payant,
  conservation des pièces remplacées, notes, 1 à 100 lignes (`line_type` `QuoteLineType`,
  désignation, `quantity_milli`, `unit` `QuoteUnit`, `unit_price_cents`, `vat_rate_code`). Un seul
  brouillon ou devis envoyé à la fois par demande (409), 20 brouillons par prestataire (409).
  Champs fixés par la plateforme : `prohibited` (422). Requête limitée à 128 Ko (413).
- **Calcul** (`Support/QuoteCalculator`) : entiers seulement (centimes, millièmes, points de
  base) ; HT de chaque ligne arrondi au centime, TVA par taux sur la somme HT de ce taux,
  demi-centime vers le haut, TTC = HT + TVA ; plafonds techniques contre le dépassement de
  capacité (`marketplace.quotes`). Méthode à faire valider par l'expert-comptable.
- **TVA** : le code de chaque ligne est celui d'une règle Compliance `vat_rate` **vérifiée** en
  vigueur dans le pays du prestataire (Action publique `Compliance\Actions\GetVatRates`), sinon
  422 sur la ligne ; un taux `exclusive` (franchise, autoliquidation) s'applique seul (422).
  **Sans aucun taux vérifié : 409 `{"code": "vat_rates_unavailable"}`** : aucun devis possible
  en production avant la vérification des taux par l'expert-comptable (décision 49).
  `tests/Arch/MarketplaceNoFiscalValuesTest.php` refuse tout décimal ou grand nombre hors
  plafonds techniques dans le module.
- **Envoi** (`POST …/send`, `SendQuote`) : conditions revérifiées ; taux du jour copiés dans
  chaque ligne (code, version, valeur, libellé, mention, certification) ; numéro `D-AAAA-NNNN`
  par prestataire (sous verrou de son identité ; une nouvelle version garde le numéro) ;
  identité de l'émetteur copiée (`issuer_snapshot`, avec `branding` si Premium) ; validité
  jusqu'à la fin du jour (fuseau du pays) ; **`content_hash` (SHA-256 du contenu)** ; le devis
  envoyé précédent de la demande passe à `superseded`. Statuts `QuoteStatus` : `draft`, `sent`,
  `accepted`, `declined`, `withdrawn`, `expired`, `superseded`. Nouvelle version
  (`POST …/revise`, depuis envoyé, refusé ou expiré, 201), retrait par le prestataire
  (`POST …/withdraw`, envoyé seulement).
- **Réponses du client** : `POST …/accept` (`content_hash` lu, sinon 409 ; `accept` ;
  `certifications` = codes des taux qui demandent une attestation, toutes et seulement elles, 422
  sinon ; `express_execution` pour un consommateur, 422 pour un professionnel ; validité dépassée
  ou devis masqué : 409 ; preuve : date, IP, cases cochées ; décision 41), `POST …/decline`
  (`reason` `DeclineReason`, `comment`), `POST …/withdrawal` (`confirm` ; consommateur,
  `marketplace.withdrawal_days` = 14 jours après l'acceptation, une fois ; décision 42).
- **Visibilité** (`QuotePolicy`) : le prestataire voit son devis à tout statut, le client une
  fois envoyé ; **tout autre utilisateur reçoit 404, admins compris** ; une partie qui tente
  l'action de l'autre : 403. `GET /me/quotes?box=sent|received`. La réponse
  (`QuoteResource`, `viewer_role`) donne aussi `mentions` (TVA, certifications, droit de
  rétractation, « Loopartner n'est pas partie au contrat »). Aucun id interne ; seules les
  coordonnées professionnelles copiées dans le devis apparaissent.
- **PDF** (`GET /me/quotes/{uuid}/pdf`, **gratuit pour les deux parties**, décision 43 ; brouillon
  409) : `Support/QuotePdf` construit un HTML échappé à partir du contenu figé, converti par
  `Support/Pdf` (paquet `dompdf/dompdf`, décision 48 : ressources distantes, PHP et JavaScript
  désactivés, `data://` seul, chroot sur les polices, cache dans `storage/framework/cache/dompdf`).
  Premium (`marketplace.quote_branding`) : logo et mentions personnalisées **figés à l'envoi**
  (décision 51). Le logo du profil (Action publique `Accounts\Actions\GetProviderLogo`) est
  copié par `Support/QuoteLogo` : PNG réduit (400, 300, 200 puis 128 px) en base64 dans
  `marketplace_quotes.issuer_logo`, 256 Ko au plus (`marketplace.quotes.logo`), sinon devis sans
  logo ; empreinte dans `issuer_snapshot.branding.logo_sha256`, donc dans `content_hash`. Le PDF
  n'utilise que cette copie (jamais le profil) ; `issuer_logo` est `$hidden` et n'apparaît dans
  aucune réponse ni dans le journal (`QuoteLogoSnapshotTest`). Une nouvelle version refait la
  copie à son envoi. Un devis envoyé avant la décision 51 sort sans logo. Aucune vue n'est
  servie par l'API.
- **Comparateur** (`ProjectQuotesController`, `Support/QuoteComparison`) :
  `GET /me/projects/{uuid}/quotes` (gratuit, paginé), `…/quotes/comparison` et
  `…/comparison.pdf` (Premium : `marketplace.quote_comparison`, `quote_comparison_export` ;
  **403 `{"code": "premium_required", "feature"}`**) ; projet d'autrui : 404 (Action publique
  `Directory\Actions\FindOwnProject`). Devis envoyés ou acceptés, non masqués, 10 au plus, dans
  l'ordre de réception. Chaque constat : `rule`, `severity` (`info`, `attention`), `quote`
  (null : constat global), `message`, `reason` (le calcul), `question`. Règles et seuils dans
  `marketplace.comparison.rules` ; `disclaimer` dans la réponse. Pas d'IA (lot 5 : facultative).
- **Accès anticipé** : voir « Module Directory (projets) ».
- **Signalement** : adaptateur public `offer` (slug) et adaptateur privé `quote` (uuid, signalé
  par le client : `POST /me/quotes/{uuid}/report`, `category` de `config/moderation.php`,
  `comment`, 409 si déjà en cours ; décision 45). Devis masqué : contenu caché au client, ni
  acceptation ni PDF.
- **Emails** (`QuoteNotification`, textes dans `Enums/QuoteNotice`, file après commit, langue du
  destinataire) : devis reçu, refusé (`marketplace.quotes`, facultative) ; acceptation (aux deux
  parties, **PDF du devis accepté joint**, généré à l'envoi) et rétractation
  (`marketplace.quote_decisions`, obligatoire). Numéro du devis seulement : jamais de montant,
  de ligne, de nom ni de titre de projet (décision 46).
- **Tâches** : `marketplace:expire-quotes` (chaque heure), `marketplace:prune` (05:15 :
  brouillons 12 mois sans modification, devis clos 12 mois, `config/security.php`, `retention`).
  Les devis acceptés disparaissent avec leur conversation (clé étrangère en cascade vers
  `match_requests`, décision 47).
- **Suppression de compte** (`ForgetUserMarketplace`, sur `AccountDeleting`) : offres, identité
  et devis non acceptés supprimés ; devis acceptés conservés pour l'autre partie (lien du compte
  à null) jusqu'à la purge de la conversation orpheline.

## Module Appointments (rendez-vous)

Rendez-vous entre prestataires et porteurs de projets (lot 4, décisions 52 à 59). Réglages dans
`config/appointments.php` (quotas gratuits et Premium par plan, bornes), limites et durées dans
`config/security.php`. Sources, textes et **tableau des points à faire valider par le juriste** :
`docs/v1/appointments-rules.md`.

- **Disponibilités** (prestataire avec un email vérifié, `CalendarPolicy` : 403 pour les autres
  rôles) : `GET|PUT /me/appointments/calendar` (fuseau IANA, ouverture, page publique, pas de 15,
  30 ou 60 minutes, marge, délai de prévenance en heures, horizon, délais de rappel ; réponse
  avec `effective` et `limits`), `/me/appointment-types` (CRUD ; titre, description, durée,
  `location_mode` `provider_place|client_place|video|phone`, `location_details` chiffré : adresse
  ou lien https `SafeExternalUrl`, `visibility` `relation|public`, `auto_confirm`, `is_active`,
  `position`), `GET|PUT /me/appointments/availability/rules` (plages hebdomadaires en heure
  locale, remplacées en bloc), `/me/appointments/availability/exceptions` (fermeture ou ouverture
  ponctuelle, un an au plus), `GET /me/appointments/slots?type=&from=&to=` (aperçu). Ouvrir les
  réservations exige un profil publié avec une catégorie (403). L'élément d'un autre prestataire :
  404.
- **Créneaux** (`Support/SlotGenerator`) : plages converties en UTC **pour chaque date**
  (`LocalTime`), créneaux découpés en temps réel dans chaque plage au pas du calendrier ;
  exceptions appliquées ; délai de prévenance, horizon et créneaux retenus (marge comprise)
  écartés. Le même calcul sert à l'affichage et au contrôle d'une réservation. Réponses : UTC et
  `local` (`starts_at`, `ends_at`, `offset`).
- **Qui réserve chez qui** (décision 52) : dans une **demande de mise en relation acceptée**
  (`GET /me/match-requests/{uuid}/booking`, `GET|POST /me/match-requests/{uuid}/appointments`) :
  le client réserve (confirmé tout de suite si `auto_confirm`, sinon `requested`), le prestataire
  propose (`proposed`). **Page publique Premium** (`GET /providers/{slug}/booking` public, limité
  par IP ; `POST /providers/{slug}/appointments` particulier ou entrepreneur) : motif de 20 à 500
  caractères, demande de contact Directory créée dans la même transaction (Action publique
  `Directory\Actions\ManageAppointmentContact`, sans email Directory), confirmation obligatoire du
  prestataire (accepte la demande, révèle le nom) ; relation acceptée existante : **409
  `{"code": "relation_exists", "match_request"}`** ; page fermée ou sans Premium : 404.
- **Double réservation impossible** (`Support/SlotLedger`) : verrou de la ligne
  `appointment_calendars` du prestataire, contrôle par le même calcul, puis index **unique**
  `appointment_slot_holds.slot_key` (calendrier et début UTC) : **409
  `{"code": "slot_unavailable"}`** (`appointments.slot_conflict` dans le journal). Créneaux
  libérés au refus, à l'annulation, à l'expiration et au report.
- **Statuts** (`AppointmentStatus`) : `requested`, `proposed` → `confirmed` | `declined` |
  `expired` ; `confirmed` → `cancelled` ; « passé » se déduit de `ends_at`. `/me/appointments`
  (`role`, `scope` `upcoming|past|all`, `status`), `/{uuid}`, `confirm` (partie attendue ; adresse
  ou téléphone du client si le lieu l'exige), `decline` (message), `cancel` (`reason`
  `schedule_conflict|no_longer_needed|other`, message ; jusqu'au début ; une demande en attente
  ne s'annule que par son auteur), `reschedule` (créneau retenu jusqu'à la réponse ; déplacement
  direct du client sur un type `auto_confirm`), `accept-change`, `decline-change` (l'auteur peut
  retirer sa proposition), 3 reports au plus, `calendar.ics` (lieu ou lien une fois confirmé),
  `notes/{noteUuid}/report`. Demande, proposition ou report sans réponse : expiré après
  48 heures ou plus tôt au délai de prévenance (`appointments:expire`). Le rendez-vous d'autrui :
  **404, admins compris** ; l'action de l'autre partie : 403 ; vérifié avant la validation.
- **Ce que chaque partie voit** (décision 53, `Support/AppointmentPresenter`) : client : résumé
  public du prestataire ; prestataire : type de compte, pays et **nom seulement une fois la
  demande acceptée** ; lieu seulement une fois confirmé ; aucun id interne, email ni téléphone
  d'un compte ; compte supprimé : `{"deleted": true}`.
- **Quotas** (décision 54, `Support/Plan`, `config/entitlements.php`, prestataire) :
  `appointments.public_booking_page`, `active_types` (2 / 10 ; fin du Premium : les premiers
  types restent réservables), `weekly_ranges` (10 / 40), `upcoming_exceptions` (20 / 100),
  `horizon_days` (30 / 90), `upcoming_appointments` (30 / 200), `reminder_offsets` (`[1440]` /
  `[120, 1440, 2880]`), `max_reminder_offsets` (1 / 2) ; au-delà : **403
  `{"code": "premium_required", "feature"}`** (`Exceptions/PremiumFeatureRequired`). Clients :
  5 demandes en attente, 20 rendez-vous à venir, 3 par relation ; prestataire : 3 propositions
  en attente par relation, 30 au total (409).
- **Emails** (`AppointmentNotification`, textes dans `Enums/AppointmentNotice`, file après commit,
  chiffrés dans la file, langue et fuseau du destinataire) : `appointments.bookings`
  (obligatoire) et `appointments.reminders` (facultative). Titre du type et date seulement ;
  **`.ics` (titre et dates) joint à la confirmation et au report accepté** ; jamais de note, de
  lieu, de lien ni de nom.
- **Rappels** (`appointments:send-reminders`, toutes les 5 minutes) : aux deux parties, en
  **durée réelle**, réservés dans `appointment_reminders` avant l'envoi (index unique), remis à
  zéro par un report, pas de rappel dont l'heure était passée à la confirmation.
- **Agenda** : adaptateur `AppointmentsAgendaSource` (abonnement : confirmés seulement, titre
  « Rendez-vous : <type> »). Events et Compliance fournissent `EventsAgendaSource` et
  `ComplianceAgendaSource`, sans rien changer à leur comportement.
- **Signalement** : adaptateurs `appointment_type` (public : page de réservation) et
  `appointment_note` (privé : signalé par l'autre partie ; son propre texte : 422).
- **Données chiffrées** : lieu des rendez-vous, détail du lieu des types, textes libres.
- **Suppression de compte** (`ForgetUserAppointments`, sur `AccountDeleting`) : rendez-vous à
  venir annulés (`cancelled_by = system`, l'autre partie prévenue), lieu donné par la personne
  effacé, calendrier supprimé, rendez-vous passés détachés.
- **Minimisation** (`appointments:prune`, 04:45, décision 57) : lieux et notes effacés 3 mois
  après la fin ou la clôture ; rendez-vous clos supprimés 12 mois après leur clôture (colonnes
  `cancelled_at`, `declined_at`, `expired_at`) ; passés 24 mois après leur fin ; traces de rappel
  et exceptions passées après 3 mois. Les rendez-vous disparaissent aussi avec leur demande de
  mise en relation (clé étrangère en cascade).
- **Export (lot 11)** : `Support/AppointmentsPersonalData::for(User)` (interne, non exposé,
  testé ; décision 59).

## Module Workspaces (projets privés, copilote)

Workspaces privés des porteurs de projet, modèles de projet, copilote de projet (lot 5,
décisions 60 à 70). Réglages dans `config/workspaces.php` (quotas par plan, limites de contenu,
copilote), limites de débit et durées dans `config/security.php`. Sources, contenu exact envoyé
à l'IA, textes, étapes de l'exploitant et **tableau J1 à J11 à faire valider par le juriste** :
`docs/v1/workspaces-rules.md`.

- **Accès** (`WorkspacePolicy`) : particuliers et entrepreneurs (403 pour les autres rôles, admins
  compris) ; email vérifié **non requis** pour le workspace. **Le workspace d'un autre utilisateur
  donne 404**, vérifié avant la validation (`Http/Requests/WorkspaceRequest` résout le workspace de
  l'URL dans `authorize()`). Aucune route publique : rien d'un workspace n'est visible d'autrui,
  donc aucun adaptateur de modération (un texte ne sort que par un brouillon Directory, signalable
  comme tout projet).
- **Tables** : `workspaces` (titre et description **chiffrés**, pays, budget, début souhaité,
  `status` `active|archived`, `template_code`, `linked_project_id` unique en `nullOnDelete`,
  `activated_at`), `workspace_goals`, `workspace_steps` (catégorie utile, `source`
  `user|copilot|template`, `ai_generated_at`), `workspace_tasks` (note chiffrée),
  `workspace_specifications` (sections à liste fermée `Support/SpecificationSections`, chiffrées),
  `workspace_copilot_suggestions` (réponse nettoyée, chiffrée), `workspace_copilot_entries`
  (décompte des générations réussies, gardé tant que le compte existe).
- **Endpoints** (`auth:sanctum`) : `/me/workspaces` (liste avec `meta` du quota, création avec
  `template` facultatif, détail avec objectifs, étapes, tâches et `progress`, PATCH, suppression,
  `archive`, `unarchive`, `activate`), `…/goals`, `…/steps`, `…/steps/{step}/tasks`,
  `…/tasks/{task}` (POST, PATCH avec `achieved`/`completed`, `step` pour déplacer une tâche,
  DELETE), `PUT …/goals/order`, `…/steps/order`, `…/steps/{step}/tasks/order` (`{"order": [uuids]}`,
  liste complète, 422 sinon), `…/specifications` (CRUD, PUT complet), `…/linked-project` (POST
  `project`, DELETE), `POST …/directory-draft` (brouillon Directory, jamais publié),
  `GET /workspace-templates`. Champs fixés par la plateforme : `prohibited` (422). Requêtes
  limitées à 64 Ko. Chaque étape catégorisée donne `providers_search` (`category`, `country`)
  pour `GET /providers`.
- **Quotas** (`Support/Plan`, `config/entitlements.php`) : `workspaces.active` (gratuit 1,
  Premium 10 ; 403 `premium_required` `multiple_workspaces`, 409 au-delà), 50 workspaces au
  total, `workspaces.templates` (Premium, `workspace_templates`). Limites de contenu (409) :
  20 objectifs, 30 étapes, 50 tâches par étape, 300 par workspace, 20 cahiers des charges.
  **Fin du Premium** (`Support/Workspaces`) : les workspaces actifs les plus récemment activés
  restent modifiables, les autres sont `locked` (lecture, archivage, suppression ; 403
  `multiple_workspaces` sinon) ; archivé : lecture seule (409).
- **Modèles de projet** : JSON de `database/data/workspaces/templates` (`Support/Templates`,
  français et anglais, catégories vérifiées par un test), copiés à la création ; aucune démarche
  juridique, fiscale ou comptable détaillée.
- **Copilote** (`/me/copilot`, `/me/workspaces/{uuid}/copilot/…`) : `GET /me/copilot`
  (`available`, `provider`, `notice` avec sa version, `disclaimer`, `sensitive_data_warning`,
  `quota`), `GET …/preview` (**contenu exact qui serait envoyé**, expurgé, sans appel),
  `POST …/roadmap` et `POST …/specification` (`notice_version` en vigueur obligatoire, 422 sinon ;
  **email vérifié obligatoire, 403 `{"code": "email_not_verified"}`** vérifié avant la
  validation), `GET …/suggestions`, `GET …/suggestions/{suggestion}`, `POST …/apply` (`steps`
  facultatif), `POST …/dismiss`. Ordre des contrôles : rôle, workspace (404), email, validation,
  workspace modifiable, IA disponible (503), verrou par utilisateur (409
  `generation_in_progress`), quotas, plafonds de coût, appel, nettoyage (502).
- **Contenu envoyé** (`Support/CopilotPayload`, liste blanche) : tâche, langue, type de compte,
  titre et description **expurgés** (`Support/Redactor` : emails, liens et domaines, IBAN,
  SIREN/SIRET, téléphones), pays, travail à distance, budget, mois de début, titres des étapes
  existantes, catégories actives ; pour un cahier des charges, l'étape et la catégorie.
  **Jamais** : nom, email, identifiants, ville, objectifs, notes, données d'un tiers ou d'un
  autre module. Consignes fixes et versionnées (`Support/CopilotPrompts`) : aucun avis juridique,
  fiscal ou comptable, catégories seulement.
- **Réponse** (`Support/CopilotOutput`, `Support/GeneratedText`) : JSON imposé par un schéma puis
  nettoyé (HTML écarté, caractères invisibles et liens retirés, longueurs bornées, 12 étapes de
  8 tâches, 6 catégories actives) ; gardée comme **suggestion** ; appliquée, elle crée des
  éléments `source: copilot` dont `ai_generated` reste vrai même modifiés. Chaque réponse porte
  `ai` (`generated`, `provider`, `model`, `prompt_version`, `disclaimer`). Textes :
  `Support/CopilotTexts` (`NOTICE_VERSION`, à changer avec le texte).
- **Quotas du copilote** (`Support/CopilotQuota`, décision 61) : gratuit **2 feuilles de route à
  vie** (une et une régénération ; un échec ne compte pas ; 403 `copilot_roadmaps`), pas de cahier
  des charges (403 `copilot_specifications`) ; Premium **30 par mois et 10 par jour** (429
  `{"code": "ai_quota_exceeded", "scope", "resets_at"}`), comptés sous un verrou par utilisateur.
- **Agenda** : `Support/WorkspacesAgendaSource` (échéances des étapes et tâches des workspaces
  modifiables, titres et dates, aussi dans l'abonnement).
- **Suppression de compte** (`ForgetUserWorkspaces`, sur `AccountDeleting`) : tout est supprimé.
  **Minimisation** (`workspaces:prune`, 04:15) : suggestions supprimées 90 jours après leur
  génération (`retention.copilot_suggestions_days`). **Export (lot 11)** :
  `Support/WorkspacesPersonalData::for(User)` (interne, testé).

## Brique d'IA et parcours d'accueil (noyau)

- **Brique d'IA** (`App\Support\Ai`, décisions 34 et 60 à 70, `config/ai.php`) : contrat
  `AiClient`, pilotes `disabled` (**défaut, production comprise**), `fake` (tests et démonstration,
  imposé par `phpunit.xml`) et `mistral` (`Drivers/MistralAiClient` : client `Http`, aucun paquet,
  **seul l'endpoint de l'UE `https://api.eu.mistral.ai` est accepté**, sinon le pilote reste
  désactivé et n'envoie rien). Les modules passent **uniquement** par `AiGateway::generate()` :
  disponibilité (pilote, prix du modèle, `max_execution_time` de PHP au moins
  `AI_TIMEOUT` + 15 s ou nul, sinon 503 `ai_unavailable` avec `reason`), verrou par utilisateur
  (409), réservation du coût maximal sous verrou (`AiBudget`, tables `ai_budgets` et `ai_usages`),
  coût réel après l'appel, totaux par jour sans utilisateur (`ai_usage_stats`). Plafonds en cents
  (`AI_USER_MONTHLY_BUDGET_CENTS`, `AI_GLOBAL_MONTHLY_BUDGET_CENTS`, `AI_GLOBAL_DAILY_BUDGET_CENTS`) :
  utilisateur 429 `ai_quota_exceeded` (`scope: budget`), global 503 (`reason: capacity`). Prix par
  modèle en micro-unités (`ai.pricing`). Réponse inexploitable : 502 `ai_invalid_response`
  (jetons comptés dans le coût, pas dans le quota). **Jamais de contenu** dans `ai_usages` ni dans
  le journal security. Tests : `Http::preventStrayRequests` et pilote simulé
  (`FakeAiClient::respondWith()`, `requests()`).
- **L'IA ne doit pas être activée sur l'hébergement mutualisé** (appel synchrone,
  décision 70) : `security:check-config` signale **en erreur** une IA activée avec un
  `max_execution_time` (lu dans le php.ini, `get_cfg_var` ; 30 s si absent) inférieur à
  `AI_TIMEOUT` + 15 s, et l'API refuse l'appel (503). Activation : étapes de
  `docs/v1/workspaces-rules.md`, section 7, puis `php artisan ai:test-connection` (requête fixe,
  sans donnée d'utilisateur).
- **Suivi des coûts** : `GET /admin/ai/usage?month=AAAA-MM` (admin avec 2FA de session,
  `EnsureModerator`, limiteur `ai-admin`) : totaux sans identité ni contenu. Alertes email aux
  admins avec 2FA à 80 % et 100 % du budget mensuel global (`AiBudgetAlerts`,
  `App\Notifications\AiBudgetAlert`, catégorie obligatoire `ai.budget_alerts`, une fois par palier
  et par mois). `ai:prune` (04:20) : réservations abandonnées depuis plus d'une heure libérées,
  journal des appels et compteurs par utilisateur supprimés après 13 mois
  (`retention.ai_usages_months`). Export (lot 11) : `App\Support\Ai\AiUsagePersonalData`.
- **Parcours d'accueil** (`App\Support\Onboarding`, décision 67) : contrat `OnboardingStep`, un
  adaptateur par module déclaré dans `config/onboarding.php` (ordre d'affichage), état **calculé
  sur les données réelles**. `GET /me/onboarding` (étapes du rôle : `key`, `label`, `optional`,
  `done`, `skipped` ; `completed`, `progress`, `dismissed`), `POST /me/onboarding/dismiss`,
  `POST /me/onboarding/steps/{key}/skip` (facultative seulement ; obligatoire 409, inconnue ou
  d'un autre rôle 404), limiteur `onboarding-writes`, email vérifié non requis. Table
  `onboarding_states` (choix de l'utilisateur seulement). `GET /me` expose `onboarding`
  (`completed`, `dismissed`). Aucun email.

## Catégories de services (noyau)

Modèle partagé `App\Models\ServiceCategory` (table `service_categories`), utilisable par tous les
modules comme `User` et `Media`.

- Deux niveaux au maximum : catégories parentes et sous-catégories. Le modèle refuse un troisième
  niveau (`LogicException` au `saving`).
- `name` est un JSON traduit `{"fr": "…", "en": "…"}` ; `nameIn($locale)` se replie sur le français.
- Seul le `slug` est exposé par l'API, jamais l'`id` ni le `parent_id`.
- On ne supprime pas une catégorie, on la désactive (`is_active`). Les clés étrangères qui la
  référencent sont en `restrict`. Le scope `active()` exclut aussi les sous-catégories d'un parent inactif.
- Valider un slug reçu : règle `App\Rules\ActiveServiceCategory`. Filtrer sur une catégorie en
  incluant ses sous-catégories : `ServiceCategory::idsWithDescendants($slug)`.
- Exposer une catégorie : `App\Http\Resources\ServiceCategoryResource` (slug et nom traduit selon
  `Locale::fromRequest()`).
- `GET /api/v1/categories` (public) : arbre des catégories actives, en-tête `Vary: Accept-Language`.
- `ServiceCategorySeeder` tourne dans **tous** les environnements (données de référence). Il crée les
  catégories manquantes avec `firstOrCreate` et ne modifie jamais une catégorie existante.
- Pas encore d'endpoint d'écriture : la gestion se fera dans le back-office.

## API et authentification

- Toutes les routes métier sont sous `/api/v1`. Toutes les erreurs sur `api/*` sont rendues en JSON,
  et `ForceJsonOnApi` traite toute requête `api/*` comme une requête JSON (sinon `auth`, `verified`
  ou `password.confirm` redirigeraient vers une page inexistante : erreur 500). La racine `/`
  répond `{"name": "Loopartner API"}` ; aucune vue n'est servie. Le disque privé `local` n'est
  pas exposé (`serve => false`).
- Sanctum fonctionne en **mode SPA** : authentification par cookie de session, sans jeton dans le
  navigateur. Le frontend appelle d'abord `GET /sanctum/csrf-cookie`, puis envoie le header `X-XSRF-TOKEN`.
- Fortify fonctionne en mode headless (`views => false`). Ses routes sont sous `/api/v1/auth` (login,
  logout, forgot-password, reset-password, user/password, user/confirm-password, two-factor…).
  Les fonctionnalités `registration` et `emailVerification` de Fortify sont **désactivées** :
  l'inscription et la vérification de l'email sont gérées par le module Accounts, sous le même préfixe.
  - Le lien de réinitialisation envoyé par email pointe vers `FRONTEND_URL/reset-password`.

### Parcours d'inscription et d'authentification (pour le frontend)

Aucune réponse ne révèle si une adresse email correspond à un compte.

1. **Inscription** : `POST /api/v1/auth/register` (`name`, `email`, `password`, `password_confirmation`,
   `account_type`, `legal_documents` = `{"terms": "<version>", "privacy": "<version>"}` (versions en
   vigueur, lues dans `GET /legal/documents`), et facultatifs `locale`, `timezone`, `country`).
   Version absente ou périmée : 422 sur `legal_documents` ; aucune version en vigueur : **503**
   `{"code": "legal_documents_unavailable"}`. Réponse **toujours 202**
   `{"message": "Vérifiez votre boîte mail pour terminer votre inscription."}`, que l'adresse soit libre
   ou déjà utilisée. **L'utilisateur n'est pas connecté.** Seules les erreurs de validation (422)
   indépendantes de l'existence du compte sont renvoyées (mot de passe trop court ou compromis…).
   - Adresse libre : le compte est créé (`RegisterAccount`) et le lien de vérification part par la file
     d'attente (`VerifyEmailNotification`).
   - Adresse déjà utilisée : rien n'est créé ni modifié ; le titulaire reçoit par la file d'attente
     `RegistrationAttemptNotification` (« quelqu'un a essayé de s'inscrire avec votre adresse »), avec des
     liens vers `FRONTEND_URL/login` et `FRONTEND_URL/forgot-password`.
   - Les emails passent par le planificateur : ils arrivent en moins d'une à deux minutes.
2. **Vérification** : le lien `GET /api/v1/auth/email/verify/{id}/{hash}?expires=…&signature=…` est
   signé, expire après 60 minutes et fonctionne **sans être connecté**. Il redirige toujours vers le
   frontend : `FRONTEND_URL/login?verified=1` (succès ou déjà vérifié), `?verification=expired` ou
   `?verification=invalid`. La page de connexion affiche le message correspondant.
3. **Connexion** : `GET /sanctum/csrf-cookie`, puis `POST /api/v1/auth/login`. Un compte non vérifié
   peut se connecter, mais les routes protégées par `verified` répondent 403.
   Si la 2FA est active : `{"two_factor": true}`, puis `POST /api/v1/auth/two-factor-challenge`.
4. **Renvoi du lien** : `POST /api/v1/auth/email/verification-notification`, connecté uniquement
   (202 si envoyé, 204 si l'adresse est déjà vérifiée).
5. **Mot de passe oublié** : `POST /api/v1/auth/forgot-password` répond **toujours 200** avec le même
   message, que l'adresse existe ou non. Le lien part par la file d'attente vers
   `FRONTEND_URL/reset-password?token=…&email=…`, qui appelle `POST /api/v1/auth/reset-password`.
6. **Nouvelle version des CGU ou de la politique** : toute route authentifiée du groupe `api`
   répond **403 `{"message", "code": "consent_required", "documents": [{type, version, title,
   effective_at}]}`** jusqu'à l'acceptation (`POST /me/legal/accept`). Voir « Documents légaux et
   consentements ».
7. **Équipe sans 2FA** : les routes `/admin/…` répondent **403 `{"code": "two_factor_required",
   "reason": "not_enabled" | "session"}`** ; `GET /me` expose `two_factor_required`.
- Le CORS n'autorise que `FRONTEND_URL`, avec les cookies (`supports_credentials`).
- Variables d'environnement : `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`.
- Mots de passe : 12 caractères minimum, et refus des mots de passe présents dans des fuites connues
  (`Password::defaults()` dans `AppServiceProvider`).
- Rôles (spatie/laravel-permission, garde `web`) : `particulier`, `prestataire`, `entrepreneur`,
  `redacteur`, `admin`. Ils sont créés par `RoleSeeder`.

## Documents légaux et consentements (noyau)

CGU (`terms`) et politique de confidentialité (`privacy`) versionnées (décision 14) :
`App\Models\LegalDocument`, `LegalAcceptance`, `App\Support\Legal\LegalDocuments`,
`LegalDocumentType`.

- **Publication** : `php artisan legal:publish <fichier.json>`
  (`{"type", "version", "effective_at", "title": {"fr", "en"}, "body": {"fr", "en"}}`, texte brut
  sans HTML ; version `[A-Za-z0-9._-]`, 32 caractères au plus). Une version publiée n'est jamais
  modifiée ni supprimée (empreinte `content_hash` SHA-256) ; `effective_at` (UTC, maintenant par
  défaut) ne peut pas être passé. Publiée à l'avance, elle est annoncée (`upcoming`). Le texte des
  documents réels reste à rédiger (juriste) : aucun n'est livré.
- **Version en vigueur** : la plus récente dont `effective_at` est passé. `is_demo` : poste local
  seulement.
- **Blocage** : middleware du noyau `EnsureLegalConsent`, ajouté au groupe `api` (noyau et modules)
  et placé après l'authentification ; il ne concerne que les routes authentifiées. Routes permises
  (`EnsureLegalConsent::ALLOWED_ROUTES`) : lire les documents, `GET /me/legal`,
  `POST /me/legal/accept`, `DELETE /me`. La déconnexion (Fortify) n'est pas dans le groupe `api`.
  Sans aucune version en vigueur (base neuve, tests), rien n'est exigé des comptes mais
  l'inscription répond 503 et `security:check-config` échoue.
- **Endpoints** : `GET /legal/documents` (public : `current` et `upcoming` de chaque type, sans le
  texte), `GET /legal/documents/{type}?version=` (public : texte, `content_hash`), `GET /me/legal`
  (`consent_required`, documents acceptés ou non, `accepted_at`), `POST /me/legal/accept`
  (`{"documents": {"terms": "<version>", "privacy": "<version>"}}`, versions en vigueur exactes,
  sinon 422 ; limiteur `legal-acceptances`).
- **Preuve** : `legal_acceptances` (date UTC, IP), écrite à l'inscription dans la même transaction
  que le compte, supprimée avec le compte ; un document accepté ne se supprime pas (`restrict`).
- Tests : `legalDocumentsInForce()` (crée les versions si besoin et renvoie
  `{"terms", "privacy"}`), utilisé par `registrationPayload()`.

## Notifications (noyau)

- Chaque notification implémente `App\Support\Notifications\CategorizedNotification`
  (`notificationCategory()`), vérifié par `tests/Feature/Core/NotificationPreferencesTest.php`.
  Catégories dans `config/notifications.php` : `mandatory` (toujours envoyée), `roles` (qui la voit),
  `label` (fr, en). Canal : `mail` seulement.
- Obligatoires : `account.security`, `events.participation` (confirmation, changement important,
  annulation, retrait), `events.organizer` et `blog.author` (décisions de modération motivées).
  Facultatives : `directory.match_requests`, `directory.messages`, `events.reminders`,
  `events.announcements`, `events.moderation_digest`, `blog.moderation_digest`,
  `compliance.reminders` (en plus de `emails_enabled` du module), `compliance.rule_coverage`,
  `moderation.digest`, `marketplace.quotes`, `appointments.reminders`. Obligatoires aussi :
  `moderation.notices`, `moderation.decisions`, `moderation.urgent_alerts`,
  `marketplace.quote_decisions`, `appointments.bookings`, `ai.budget_alerts` (admins).
- L'écouteur `App\Listeners\ApplyNotificationPreferences` (événement `NotificationSending`)
  abandonne l'envoi d'une catégorie facultative désactivée, **au moment de l'envoi** (après la
  file d'attente). Tout est activé par défaut : les modules n'ont rien changé à leur `via()`.
  `Notification::fake()` ne déclenche pas cet écouteur.
- `GET|PUT /me/notification-preferences` (email vérifié non requis) :
  `{"preferences": [{"category", "channel": "mail", "enabled": false}]}` ; catégorie d'un autre
  rôle, inconnue ou obligatoire désactivée : 422. Table `notification_preferences` (choix
  explicites seulement), supprimée avec le compte.
- Nouvelle notification : implémenter l'interface et déclarer sa catégorie dans
  `config/notifications.php`.

## Double authentification de l'équipe

Obligatoire pour `admin` et `redacteur` (décision 17, `App\Support\TeamTwoFactor`) :

- Toute fonction d'équipe exige une 2FA confirmée **et** une session ouverte avec un code :
  `/admin/…` (`EnsureModerator`, vérifié sur chaque route par
  `tests/Feature/Security/TeamTwoFactorTest.php`, `/admin/moderation` compris), publication directe
  d'un événement (admin) ou d'un article (équipe).
- `ProtectTeamTwoFactor` (dans `fortify.middleware`) : l'équipe ne peut pas désactiver sa 2FA
  (403 `two_factor_required`, journal `auth.two_factor_disable_denied`).
- Un membre de l'équipe sans 2FA garde l'usage des fonctions ouvertes à tout compte : un blocage
  global changerait le comportement attendu par les tests de Directory et de Compliance (comptes
  d'équipe sans 2FA qui reçoivent 404). Le frontend impose l'activation grâce à
  `two_factor_required` (`GET /me`).

## Sécurité transversale

- Le middleware `App\Http\Middleware\SecurityHeaders` ajoute à chaque réponse : `X-Content-Type-Options`,
  `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`, `Content-Security-Policy`
  (`default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'`),
  `Cross-Origin-Opener-Policy: same-origin` et `Cross-Origin-Resource-Policy: same-site`, plus
  `Strict-Transport-Security` (1 an, sous-domaines, sans preload) en production sur HTTPS uniquement.
- Limiteurs de débit nommés (`AppServiceProvider`), valeurs dans **`config/security.php`**
  (`rate_limits`) : `login` (5/min par email + IP, 30/h par email), `two-factor` (5/min, 20/h par
  connexion en cours), `register` (30/h par IP), `forgot-password` (5/h par email, 20/h par IP),
  `reset-password` (10/h par IP), `password-update` et `password-confirm` (5/min par utilisateur),
  `verification` (3/min, 10/h), `api` (60/min), `media-uploads` (20/min), `account-deletion` (6/min), `legal-acceptances` (20/h),
  `projects-browse` (30/min, 500/j), `public-projects` (30/min et 1 000/j par IP), `match-requests` (10/h, 20/j, propositions et contacts confondus),
  `messages` (10/min, 300/j), `reports` (10/j), `blocks` (20/j), `event-writes` (60/h, 300/j),
  `event-submissions` (5/h, 15/j), `event_revisions` (10/h, 30/j, compté par `SubmitRevision`),
  `event-registrations` (20/h, 60/j), `event-registrant-removals` (20/j), `event-announcements`
  (3/j), `event-moderation` (60/h par admin), `article-writes` (60/h, 300/j), `article-submissions`
  (5/h, 10/j), `article_revisions` (10/h, 30/j, compté par `SubmitRevision`), `blog-moderation`
  (60/h par modérateur), `premium-admin` (30/h par admin), `compliance-rules-admin` (60/h par
  admin), `compliance-writes` (120/h, 600/j), `compliance-companies` (10/j),
  `compliance-revenue` (60/h), `compliance-exports` (10/h), `compliance-feed-manage` (10/j),
  `compliance-feed` (30/h par abonnement, 120/h par IP, route publique à jeton),
  `moderation-notices` (5/h et 20/j par IP, 10/j par compte, 5/j par email compté dans
  `SubmitNotice`), `moderation-lookups` (30/h par IP), `moderation-appeals` (5/j),
  `moderation-admin` (60/h par admin), `seller-profile-writes` (20/h), `offer-writes` (60/h,
  300/j), `quote-writes` (120/h, 600/j), `quote-sends` (20/h, 60/j), `quote-decisions` (30/h),
  `quote-pdfs` (30/h, 200/j, devis et comparaison), `quote-comparisons` (60/h),
  `appointment-settings-writes` (60/h, 300/j), `appointment-slots` (60/min, 1000/j par compte),
  `appointment-public-slots` (30/min par IP), `appointment-bookings` (10/h, 30/j : réservations,
  propositions, reports), `appointment-decisions` (60/h), `appointment-ics` (30/h), `agenda`
  (60/min), `agenda-feed-manage` (10/j), `agenda-feed` (30/h par abonnement, 120/h par IP),
  `workspace-writes` (120/h, 600/j), `copilot-generations` (5/h, 20/j), `ai-admin` (60/h par
  admin), `onboarding-writes` (30/h).
- Règles de validation du texte saisi : `NoHtml` et `NoInvisibleCharacters` (caractères de contrôle
  sauf tabulation et retours à la ligne, contrôles de direction U+202A–202E et U+2066–2069, espace
  de largeur nulle U+200B, marques de direction U+200E et U+200F, U+2060–2064, BOM…), sur **tous**
  les champs texte saisis. Restent autorisés U+200C (antiliant : persan, langues indiennes) et
  U+200D (liant : emojis composés comme 👨‍👩‍👧 ou 👩‍💻).
- **Journal de sécurité** : canal `security` (`storage/logs/security-AAAA-MM-JJ.log`, 30 jours), écrit
  par `App\Support\SecurityLog::record()`. Chaque entrée : `event`, `user_id`, `ip`, `route`, `at`
  (UTC). Jamais d'email (utiliser `SecurityLog::emailHash()`), de nom, de mot de passe, de jeton ni
  de code 2FA. Événements : `auth.login`, `auth.login_failed`, `auth.logout`, `auth.registered`,
  `auth.registration_existing_email`, `auth.email_verified`, `auth.email_verification_failed`,
  `auth.password_reset_requested` (avec `outcome`), `auth.password_reset`, `auth.password_changed`,
  `auth.two_factor_enabled|confirmed|disabled|failed|recovery_codes_regenerated`, `account.deleted`,
  `authorization.denied` (`Gate::after`, avec `ability`, `resource`, `resource_ref`, `status`),
  `rate_limit.exceeded`, `projects.browse`, `projects.view`, `projects.scraping_suspected`,
  `match_requests.created`, `match_requests.limit_exceeded` (avec `window`), `messaging.user_blocked`,
  `messaging.user_unblocked`, `moderation.report_created`, `moderation.access_denied` (avec
  `reason` : `role`, `two_factor_disabled`, `two_factor_session`), `events.submitted`,
  `events.submission_withdrawn`, `events.pending_limit_reached`, `events.published_directly`,
  `events.updated_directly`, `events.approved`, `events.rejected`, `events.expired_in_review`,
  `events.revision_submitted` (avec `changed_fields`), `events.revision_withdrawn`,
  `events.revision_approved`, `events.revision_rejected`, `events.cancelled` (avec `by`),
  `events.registration_blocked`, `events.registration_removed`, `events.announcement_submitted`,
  `events.announcement_published`, `events.announcement_approved`, `events.announcement_rejected`,
  `blog.article_submitted`, `blog.submission_withdrawn`, `blog.pending_limit_reached`,
  `blog.quota_reached`, `blog.published_directly`, `blog.updated_directly` (avec
  `changed_fields`), `blog.article_approved`, `blog.article_rejected`, `blog.article_unpublished`,
  `blog.revision_submitted` (avec `changed_fields`), `blog.revision_withdrawn`,
  `blog.revision_approved`, `blog.revision_rejected`, `blog.self_moderation_denied`,
  `premium.granted`, `premium.revoked` (uuids et `subject_user_id`, jamais de motif),
  `compliance.rule_created|updated|revised|verified|retired|deleted` (uuid, code, pays, version),
  `compliance.rule_self_verification_denied`, `compliance.rules_imported`,
  `compliance.company_created|deleted`, `compliance.premium_required` (avec `feature`),
  `compliance.calendar_feed_created|regenerated|revoked|invalid` (jamais le jeton ;
  `invalid` au plus une fois par heure et par IP), `compliance.export_downloaded`,
  `legal.document_published` (type, version, date d'entrée en vigueur), `legal.accepted` (type et
  version), `auth.two_factor_disable_denied`, `moderation.notice_submitted` (catégorie, priorité,
  source), `moderation.notice_limit_exceeded`, `moderation.decision_taken` (mesure, fondement,
  catégorie, nombre de signalements), `moderation.restriction_lifted`,
  `moderation.self_moderation_denied`, `moderation.law_enforcement_referred` (autorité),
  `moderation.appeal_submitted`, `moderation.appeal_decided` (issue),
  `moderation.appeal_self_review_denied` (uuids seulement : jamais l'identité ni l'email du
  signalant, son explication, la copie du contenu, les faits, ni le texte d'un recours),
  `marketplace.seller_profile_updated`, `marketplace.offer_published|unpublished|deleted`,
  `marketplace.quote_sent` (avec `version`), `marketplace.quote_revised`,
  `marketplace.quote_withdrawn`, `marketplace.quote_draft_deleted`, `marketplace.quote_accepted`,
  `marketplace.quote_declined` (avec `reason`), `marketplace.quote_withdrawal_exercised`,
  `marketplace.quote_pdf_downloaded`, `marketplace.comparison_pdf_downloaded`,
  `marketplace.premium_required` (avec `feature`) (uuids seulement),
  `appointments.requested|proposed|confirmed|declined|expired|rescheduled`,
  `appointments.reschedule_proposed|reschedule_declined`, `appointments.cancelled` (avec `by` et
  `reason`), `appointments.slot_conflict`, `appointments.public_page_enabled|disabled`,
  `appointments.premium_required` (avec `feature`), `agenda.feed_created|regenerated|revoked|invalid`
  (uuids seulement, jamais le jeton), `workspaces.created|deleted|directory_draft_created`,
  `workspaces.premium_required` (avec `feature`), `copilot.generated` (type, uuids, nombre de
  remplacements), `copilot.suggestion_applied`, `copilot.quota_exceeded` (avec `scope`),
  `ai.completed` (fonctionnalité, modèle, jetons), `ai.unavailable` et `ai.provider_failed` (avec
  `reason`), `ai.invalid_response`, `ai.budget_exceeded` (avec `scope`), `ai.budget_alert`,
  `ai.usage_viewed`.
  **Jamais le contenu d'un message, d'une demande, d'un commentaire de signalement, la description
  d'un événement, son lien de connexion, le texte d'une annonce, le contenu d'un article, un motif,
  une donnée des inscrits, ni un numéro d'entreprise, un nom d'entreprise, un montant, une note ou
  un jeton du suivi administratif** (tests `MatchingPrivacyTest`, `EventsPrivacyTest`,
  `BlogInputAndPrivacyTest` et `ComplianceInputAndPrivacyTest`), **ni une identité
  professionnelle, une ligne, un montant, un titre, un commentaire ou une empreinte d'un devis**
  (`MarketplacePrivacyTest`), **ni une note, un lieu, un téléphone, un lien de visio ou le titre
  d'un rendez-vous** (`AppointmentsPrivacyTest`), **ni un titre, une description, une note d'un
  workspace, le contenu envoyé à l'IA ou sa réponse** (`WorkspacesPrivacyTest`, `AiGatewayTest`).
  Les événements Laravel et Fortify sont journalisés par `App\Listeners\LogSecurityEvents`
  (listeners découverts automatiquement dans `app/Listeners`). Les tests gardent ce journal en
  mémoire (`securityLog()` dans `tests/Pest.php`).
- **`php artisan security:check-config`** (à lancer à chaque déploiement, après `config:cache`) :
  vérifie la configuration de production (`App\Support\Security\ConfigAudit`) et échoue (code 1)
  sur toute valeur dangereuse (`APP_DEBUG`, cookies de session, Sanctum, CORS, `LOG_LEVEL`,
  `MAIL_MAILER`, dépendances de développement installées, colonne réelle avec
  `ON UPDATE CURRENT_TIMESTAMP` hors `updated_at`, **base de démonstration** : compte de
  démonstration vérifié `<rôle>@example.com` (`ConfigAudit::DEMO_ACCOUNT_EMAILS`), règle
  Compliance ou document légal `is_demo`, **aucune version en vigueur des CGU ou de la politique de
  confidentialité**…). Les avertissements (`TRUSTED_PROXIES` vide, réglages php.ini,
  `explicit_defaults_for_timestamp` à OFF, base qui ne répond pas,
  `COMPLIANCE_REQUIRE_DISTINCT_VERIFIER` ou `MODERATION_REQUIRE_DISTINCT_APPEAL_REVIEWER` à false
  alors que deux admins ont la 2FA) ne bloquent pas. Modération : erreur sans
  `MODERATION_CONTACT_EMAIL` ou `MODERATION_AUTHORITIES_EMAIL` valides, ou sans référence pour une
  clause des CGU (`config/moderation.php`, `terms_clauses`). IA activée (`AI_DRIVER` autre que
  `disabled`) : erreur pour un pilote simulé ou inconnu, une clé `MISTRAL_API_KEY` vide, un
  `MISTRAL_BASE_URL` autre que l'endpoint de l'UE en https, un modèle sans prix, un plafond nul ou
  un `max_execution_time` du php.ini inférieur à `AI_TIMEOUT` + 15 s (hébergement mutualisé). Le schéma est lu par `App\Support\Database\TimestampSchema` (MySQL et MariaDB seulement) ;
  relancer la commande après `migrate`. Toute nouvelle variable de sécurité doit y être ajoutée,
  avec son test dans `tests/Feature/Security/CheckConfigCommandTest.php`.
- URL générées : `AppServiceProvider` force la racine et le schéma de `APP_URL` (jamais l'en-tête
  `Host`). Hors local, `trustHosts` n'accepte que l'hôte de `APP_URL` et ses sous-domaines.
  Proxys de confiance : `TRUSTED_PROXIES` (`config/security.php`, `TrustConfiguredProxies`), vide
  par défaut ; à renseigner derrière Cloudflare, sinon l'IP client et le HTTPS sont mal détectés.
- La réinitialisation du mot de passe supprime toutes les sessions en base de l'utilisateur, et le
  changement de mot de passe toutes sauf la session courante (`App\Support\UserSessions`).
- Les routes Fortify n'ont pas le groupe `api` : `App\Http\Middleware\ThrottleAuthRoutes` (ajouté
  dans `fortify.middleware`) leur applique le limiteur correspondant à leur nom de route.
  `tests/Feature/Security/AuthRateLimitTest.php` vérifie chaque limite.

## Sécurité : liste de contrôle obligatoire

À appliquer à **chaque** tâche qui ajoute ou modifie un endpoint, une Action, un modèle ou une
configuration. Référentiels : OWASP API Security Top 10 et ASVS niveau 2. Voir aussi `SECURITY.md`.

1. **Autorisation de chaque endpoint, au niveau de l'objet et de la fonction.** Policy (ou
   FormRequest `authorize()`) pour chaque route : qui peut appeler la fonction (rôle, email vérifié,
   profil publié…) et à quels objets il a accès. La ressource d'un autre utilisateur donne **404**
   (`denyAsNotFound`), pas 403. Toute nouvelle route `api/*` est ajoutée à l'inventaire de
   `tests/Feature/Security/AuthorizationMatrixTest.php` (publique ou authentifiée).
2. **Validation de chaque entrée.** FormRequest avec des règles explicites pour chaque champ, y
   compris les paramètres d'URL et de requête (longueurs, formats, listes fermées, `NoHtml` sur le
   texte libre, `per_page` borné). Aucune donnée brute de la requête dans une requête SQL : liaisons
   (`?`) et `App\Support\Database\Like` pour les recherches.
3. **Champs assignables.** `#[Fillable]` limité aux champs de contenu ; identifiants, propriétaire,
   statut, dates du cycle de vie et `*_media_id` fixés par les Actions (`forceFill`). Les contrôleurs
   n'utilisent que `$request->validated()`. Les champs sensibles envoyés par le client sont ignorés
   ou refusés (`prohibited`), et un test le vérifie.
4. **Données exposées.** API Resource avec chaque champ listé : jamais d'`id` interne, de `user_id`,
   de chemin ou de disque, ni de donnée personnelle non nécessaire. Les messages d'erreur ne révèlent
   ni l'existence d'un compte, ni le nom d'un modèle, ni une valeur reçue sensible.
5. **Limites de débit.** Toute route a au moins `throttle:api`. Une route coûteuse, sensible ou
   exposée à la collecte massive a un limiteur nommé dédié, avec ses valeurs dans
   `config/security.php`, et un test du 429.
6. **Journalisation sans données sensibles.** Les événements de sécurité (authentification, refus,
   abus, actions d'administration) passent par `App\Support\SecurityLog` : jamais de mot de passe, de
   jeton, de code 2FA, d'email (utiliser `SecurityLog::emailHash()`) ni de nom. Les paramètres qui
   portent un secret ont `#[\SensitiveParameter]`.
7. **Tests des cas d'abus.** En plus du cas nominal : anonyme (401), mauvais rôle (403), objet d'un
   autre utilisateur (404), entrées invalides ou malveillantes (422), assignation de champs interdits,
   dépassement de limite (429), absence de donnée sensible dans la réponse et dans le journal. Les
   tests transversaux vont dans `tests/Feature/Security/`. Pour corriger une faille : le test échoue
   avant la correction et passe après.
8. **Configuration.** Toute nouvelle variable d'environnement liée à la sécurité est documentée dans
   `.env.example` et vérifiée par `security:check-config` (avec son test).

**Résumé de fin de tâche** : chaque résumé contient un paragraphe **« Sécurité »** qui reprend
les points 1 à 8, un par un, en indiquant ce qui a été fait (ou pourquoi le point ne s'applique pas),
ainsi que les risques restants.

## Qualité

À lancer depuis `backend/`, avec le `php` du PATH (PHP 8.4, voir « Environnement local »). Tout doit
passer avant chaque commit :

```sh
php artisan test             # Pest, SQLite en mémoire
vendor/bin/pint              # style (preset laravel)
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan niveau 6
composer security            # composer audit : vulnérabilités connues des dépendances
```

- **Les tests ne lisent jamais `backend/.env`** (`tests/TestCase.php` : dossier
  d'environnement `tests/`, fichier `tests/phpunit.env` **volontairement vide**, commentaires
  seulement, vérifié par `TestEnvironmentTest` ; sans lui, phpdotenv tentait de lire `tests/.env`
  à chaque test et PHPUnit comptait un avertissement par test) : toute leur configuration est
  dans `phpunit.xml` (dont une `APP_KEY` publique de test). Une variable d'environnement déjà définie l'emporte sur `phpunit.xml`. Hors
  SQLite, les tests refusent une base dont le nom n'annonce pas une base de test (`testing`,
  `<nom>_test`, `<nom>_testing`) : ils la vident (`RefreshDatabase`).
- **Intégration continue** (`.github/workflows/ci.yml`, GitHub Actions) : à chaque push, sur
  toutes les branches (pas de pull request : la fusion se fait en local après un résultat vert).
  Job « Qualité et tests (SQLite) » : Pint, PHPStan, `composer security`, tests. Puis, sur
  **MariaDB 10.4**, **MariaDB 11.8** et **MySQL 8.0** : migrations réelles, `ReferenceDataSeeder`,
  données de démonstration, toute la suite (base `testing`). Sur ces serveurs,
  `tests/Feature/Core/TestEnvironmentTest.php` contrôle aussi le schéma réel (aucune colonne
  `ON UPDATE CURRENT_TIMESTAMP` hors `updated_at`). Résultat : onglet **Actions** du dépôt GitHub.
  **Jusqu'au 2026-11-18, ce workflow n'a jamais tourné** : une erreur de syntaxe YAML le rendait
  invalide (échec en 0 s, « Invalid workflow file ») ; corrigée sur `fix/ci-workflow`. Aucun
  passage vert n'est donc acquis avant cette correction, sur SQLite comme sur les serveurs réels.
  Dans le workflow, toute commande qui contient « : » ou « # » s'écrit en bloc littéral
  (`run: |`), jamais en scalaire nu ; valider après chaque modification avec
  `python -c "import yaml,sys; yaml.safe_load(open(sys.argv[1], encoding='utf-8'))" .github/workflows/ci.yml`.
- La limite mémoire par défaut de PHP (128 Mo) ne suffit plus : `phpunit.xml` fixe 1 Go pour les
  tests (512 Mo ne suffisaient plus pour toute la suite au lot 4 : la mémoire s'accumule d'un test
  à l'autre), et PHPStan se lance avec `--memory-limit=1G`.
- Les helpers de test partagés (`serviceCategory()`, `projectOwner()`, `browsingProvider()`,
  `projectPayload()`, `publishedProjectOf()`, `pendingProposal()`, `acceptedConversation()`,
  `postMessage()`, `expectNoPrivateFields()`, `userWithTwoFactor()`, `verificationUrlFor()`, `securityLog()`,
  `allowDemoSeeding()`, `eventOrganizer()`, `eventPayload()`, `publishedEventOf()`, `registerTo()`,
  `moderator()`, `asModerator()`, `fromFrontend()`, `articleAuthor()`, `asAuthor()`,
  `articlePayload()`, `articleOf()`, `blogCategory()`, `makePremium()`, `complianceUser()`,
  `verifiedLegalForm()`, `verifiedDeadlineRule()`, `companyPayload()`, `complianceCompanyOf()`,
  `legalDocumentsInForce()`, `restrictContent()`, `restrictContentWith()`, `verifiedVatRate()`,
  `sellerProfilePayload()`, `marketplaceProvider()`, `offerPayload()`, `publishedOfferOf()`,
  `acceptedQuoteRequest()`, `quotePayload()`, `sentQuote()`, `appointmentProvider()`,
  `appointmentCalendarOf()`, `appointmentTypeOf()`, `weeklyAvailability()`,
  `appointmentRelation()`, `workspaceOwner()`, `workspaceOf()`, images…) sont dans
  `tests/Pest.php`. Requêtes simultanées : `simulateConcurrentRequest()` (écriture d'une
  « autre requête » au premier événement Eloquent donné), `simulateConcurrentRequestAfterQuery()`
  (juste après une requête SQL, par exemple entre « existe déjà ? » et l'insertion) et
  `recordQueries()` (requêtes et profondeur de transaction, pour vérifier un verrou ou une
  insertion hors transaction ; `for update` n'apparaît que sous MySQL et MariaDB). Une écriture
  simulée dans une transaction du code testé est annulée avec elle. Les fonctions des fichiers de test sont globales : leur nom doit être unique
  dans tout `tests/`. Un test qui fixe l'horloge (`Carbon::setTestNow`) y revient avec
  `travelTo(<date fixe>)`, jamais `travelBack()` (qui revient à l'heure réelle). Une donnée de
  session (`withSession`) sur une route `/api/v1` hors Fortify exige `fromFrontend()` : Sanctum
  n'ouvre une session que pour une requête du frontend. Les requêtes de test envoient `Accept-Language: fr-FR` par défaut
  (`tests/TestCase.php`). Un test qui change le schéma (migration rejouée, table temporaire)
  passe par `changeSchemaInTest()` : MySQL et MariaDB valident implicitement la transaction de
  `RefreshDatabase` à chaque DDL (SQLite non), et sans ce helper le `DB::transaction()` suivant
  échoue (« SAVEPOINT trans2 does not exist ») au lieu de se comporter comme en production.

- Les tests simulent l'API Have I Been Pwned avec `Http::fake` (voir `tests/Pest.php`).
- Fais des commits séparés et lisibles. Ne travaille pas directement sur `main` et ne pousse rien sans
  demande explicite.

## Frontend (Next.js)

Dossier `frontend/`, décisions 2 (modifiée), 71 à 74. Maquettes : fichier Figma « Crevia ».

- **Pile** : Next.js 16 (App Router), React 19, TypeScript strict, ESLint (`eslint-config-next`).
  Aucun framework CSS : `src/app/globals.css` porte le système de design (fond `#f3f3ed`,
  bandeau bleu `#6aa6da` → `#99cefb`, vert-anis `#e1e4ab`, cartes arrondies de 33 px, police
  Outfit pour l'interface, Inter pour les textes ; polices auto-hébergées par
  `@fontsource-variable/*`). Logos dans `public/images`. Aucun paquet sans accord de
  l'utilisateur (comme le backend).
- **Variables** : `NEXT_PUBLIC_API_URL` (adresse de l'API, sans slash final ; `frontend/.env.example`).
  Côté API : `FRONTEND_URL` (CORS et liens des emails), `SANCTUM_STATEFUL_DOMAINS` et
  `SESSION_DOMAIN` (domaine parent commun, par exemple `.loopartner.com`). Les aperçus Vercel en
  `*.vercel.app` n'ont pas de session (autre domaine) : seules les pages publiques y fonctionnent.
- **Client de l'API** (`src/lib/api.ts`) : `credentials: 'include'`, `GET /sanctum/csrf-cookie`
  avant toute écriture, en-tête `X-XSRF-TOKEN`, un nouvel essai après un 419 ; `ApiError` garde
  `status`, `code` (`consent_required`, `premium_required`, `email_not_verified`…) et les erreurs
  de champ. Un 403 `consent_required` ouvre `ConsentGate` (acceptation des CGU en vigueur).
  `apiOrNull` pour les pages publiques rendues côté serveur (API injoignable : état vide).
- **Mode démonstration** (`src/lib/demo.ts`, `NEXT_PUBLIC_DEMO_MODE=true`, **aperçus Vercel
  seulement, jamais en production**) : le client de l'API répond avec des données fictives
  (projets, articles, événements, prestataires, demandes, conversations, offres, profil et identité ;
  `demo-matching.ts`, `demo-offers.ts`) sans appel réseau, bandeau permanent, comptes de
  test `client@`, `entrepreneur@`, `prestataire@`, `comptable@` et `marques@demo.loopartner.fr` (mot de passe
  `DEMO_PASSWORD`, sans existence côté API) ; session et créations dans le `localStorage`.
- **Session** (`src/lib/session.tsx`) : `GET /me` au chargement, `useSession()` (`user`,
  `hasRole`, `refresh`, `logout`).
- **Pages** : `/` (accueil), `/projets` et `/projets/[uuid]` (vitrine publique ; description et
  proposition pour les prestataires connectés), `/publier` (4 étapes, brouillon gardé dans
  l'onglet le temps de se connecter, boutons de navigation toujours visibles, avertissement sur
  la description publique) et `/publier/confirmation`, `/connexion` (2FA comprise),
  `/inscription` (choix du profil puis compte), `/mot-de-passe-oublie`, `/nouveau-mot-de-passe`,
  `/legal/[type]`, `/espace` (profil comme le voient les autres : photo, nom, rôle, pays,
  modification par `PATCH /me` ; agenda des 30 prochains jours `GET /me/agenda` ; projets du
  porteur ; mes événements : inscriptions à venir `GET /me/event-registrations` et, pour un
  organisateur, ses webinars `GET /me/events` ; messages et raccourcis ; renvoi du lien de
  vérification), `/agenda` (vue du mois et de la journée de `GET /me/agenda`, filtres par source,
  avertissements `disclaimers`, abonnement `GET|POST|DELETE /me/agenda/feed` : URL montrée une
  seule fois ; `src/lib/agenda.ts`),
  `/prestataires`, `/articles`, `/articles/[slug]`, `/articles/rediger`, `/evenements`,
  `/evenements/[slug]` (inscription), `/evenements/proposer`, `/mes-evenements`.
  Messagerie (étape 1 du deuxième passage, présentation inspirée de codeur.com) : `/messages`
  réunit les conversations (`?c=<uuid>`) et les demandes en attente (`?d=<uuid>`, reçues en
  tête : accepter et répondre, refuser, bloquer, signaler ; envoyées : retirer) ; la proposition
  d'origine est la première bulle de la conversation ; nouveaux messages toutes les 10 s avec
  `after`, marqueur de lecture, blocage, signalement d'un message. `/demandes` redirige vers
  `/messages`. `/prestataires/[slug]/contacter`. Signalement public (DSA) : `ReportButton` sur
  les projets, articles, événements, offres et profils prestataires
  (`POST /moderation/notices`, jeton de suivi montré une fois sans compte).
  **En-tête** (`SiteHeader`) : Projets, Prestataires et Offres à gauche (dans le menu du compte
  sous 900 px), logo au centre, « Publier un projet » (« Voir les projets » pour un
  prestataire), icône des messages avec pastille (`useUnreadCount` : messages non lus et demandes
  reçues en attente, chaque minute), avatar ouvrant le menu du compte (dont la déconnexion).
  Articles et événements : pied de page seulement.
  Prestataires et offres (étape 2) : `/prestataires/[slug]` (profil public, offres, articles),
  `/offres` et `/offres/[slug]` (prix indicatif TTC, qualité déclarée du vendeur, jamais ses
  coordonnées), `/espace/profil` (PUT `/me/provider-profile`, logo, 1 à 5 catégories, publication),
  `/espace/identite` (PUT `/me/seller-profile` ; texte et version de la déclaration sur l'honneur
  recopiés de `config/marketplace.php` : à changer ensemble), `/espace/offres` (créer, modifier,
  publier, retirer, supprimer ; 409 `seller_profile_incomplete` renvoie vers l'identité).
  `/login`, `/forgot-password` et `/reset-password` (liens des emails de l'API) redirigent vers
  les pages françaises.
- **Règles d'affichage** : dates de l'API en UTC, affichées dans `Europe/Paris` (ou le fuseau de
  l'événement) ; jamais de HTML venu de l'API (`dangerouslySetInnerHTML` interdit) : le corps des
  articles est rendu bloc par bloc (`ArticleBody`) avec le `rel` fourni ; une URL écrite dans un
  texte libre n'est jamais rendue cliquable ; avertissements affichés (ni conseil juridique, IA,
  données sensibles près des champs libres) ; aucun chiffre ni label inventé (décision 74).
- **Contrôles** (depuis `frontend/`) : `npm run lint`, `npm run typecheck`, `npm run build`,
  `npm audit --omit=dev`. Job « Frontend (Next.js) » de la CI : 5 jobs doivent être verts avant
  une fusion.
