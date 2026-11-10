# Loopartner — règles du projet

Loopartner est un SaaS qui met en relation des particuliers porteurs de projets et des prestataires.
Il propose aussi un agenda d'événements, un blog et un suivi des obligations administratives des
entrepreneurs.

> Ces règles sont prioritaires sur les consignes génériques générées par Laravel Boost
> (`backend/CLAUDE.md`, `backend/AGENTS.md`) en cas de conflit.

## Dépôt

- `backend/` : API Laravel 13, PHP 8.4. Elle ne sert aucune vue : elle renvoie uniquement du JSON.
- `frontend/` (à venir) : Next.js, servi sur un autre sous-domaine. Il consomme l'API avec des cookies de session.

## Environnement local

- Le `php` du PATH Windows est en PHP 8.4 : utilise directement `php`, `composer` et `vendor/bin/...`.
- Base de données locale : MariaDB/MySQL via XAMPP (configurée dans `backend/.env` par l'utilisateur).
  Les tests utilisent SQLite en mémoire (`phpunit.xml`).
- Les migrations doivent fonctionner sur MySQL, MariaDB et SQLite : jeu de caractères `utf8mb4`,
  longueur explicite et raisonnable pour les chaînes indexées (191 caractères maximum pour un index
  sur une seule colonne `string`), pas de type ni de fonction propre à un seul moteur.
- **Ne lis jamais et ne modifie jamais `backend/.env`**. Cette interdiction est aussi bloquée dans
  `.claude/settings.json`. Seul `backend/.env.example` se modifie.
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
  `media:prune-orphans` (03:00), puis le worker.
- Toutes les dates sont stockées en **UTC** (`app.timezone = UTC`, connexion MySQL en `+00:00`).
  La conversion vers le fuseau de l'utilisateur se fait à l'affichage, côté frontend.

## Architecture modulaire

Le code métier vit dans `backend/app/Modules/<Module>/`. Les modules sont : `Accounts`, `Directory`,
`Events`, `Blog` et `Compliance`.

Chaque module contient :

```
Models/  Http/Controllers/  Http/Requests/  Http/Resources/  Policies/  Actions/  Jobs/  routes.php
```

Et, au besoin : `Enums/` (enums PHP), `Rules/` (règles de validation), `Support/` (petites classes
utilitaires du module) et `Console/Commands/` (commandes Artisan du module).

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
  tous les modules : `NoHtml` (refuse le HTML), `CountryCode` (ISO 3166-1 alpha-2) et `CurrencyCode`
  (ISO 4217). Les listes de codes sont statiques (ni package ni extension intl).
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
  DTO), jamais le modèle Eloquent.
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
- La recherche texte est un simple `LIKE` (`App\Support\Database\Like`, qui échappe `%`, `_` et `!`
  avec `ESCAPE '!'`, compatible SQLite et MariaDB 10.4). Une recherche plein texte (index FULLTEXT ou
  moteur dédié) pourra la remplacer plus tard si le volume l'exige.
- Données de référence (rôles, catégories) : `ReferenceDataSeeder`, seul seeder à lancer en production :
  `php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force`.
- Comptes de démonstration créés par `DatabaseSeeder` **uniquement si** `SEED_DEMO_DATA=true`,
  `APP_ENV=local`, `APP_DEBUG=true` et `APP_URL` locale (`DemoDataGuard`). Si `SEED_DEMO_DATA=true`
  sans les autres conditions, le seed échoue. Comptes : `<rôle>@example.com`
  pour chaque rôle, mot de passe `password`, email vérifié. Le prestataire a un profil publié avec
  trois catégories ; le particulier et l'entrepreneur ont chacun un projet publié et un brouillon
  (`DatabaseSeeder::DEMO_PROJECTS`, repérés par leur titre). Relancer le seeder republie un projet de
  démonstration expiré.

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
| `event_cover`, `article_cover` | — | — | — | pas encore disponibles (422) |

Les limites et les rôles de `event_cover` et `article_cover` seront définis avec les modules Events
et Blog. Les rôles de `article_cover` ne doivent pas être figés sur `redacteur`/`admin` : les
prestataires pourront aussi écrire des articles.

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
- **Confidentialité** : `PublishedProjectResource` n'expose ni l'email, ni le nom, ni l'identifiant du
  porteur de projet, seulement `owner.account_type` (particulier ou entrepreneur) et `owner.country`
  (`users.country`). Le contact passera par la mise en relation (à venir).
- La suppression d'un compte supprime ses projets (clé étrangère en cascade, aucun fichier rattaché).
  `DeleteProject` reste le point d'entrée unique pour supprimer un projet.

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

- Toutes les routes métier sont sous `/api/v1`. Toutes les erreurs sur `api/*` sont rendues en JSON.
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
   `account_type`, et facultatifs `locale`, `timezone`, `country`). Réponse **toujours 202**
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
- Le CORS n'autorise que `FRONTEND_URL`, avec les cookies (`supports_credentials`).
- Variables d'environnement : `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`.
- Mots de passe : 12 caractères minimum, et refus des mots de passe présents dans des fuites connues
  (`Password::defaults()` dans `AppServiceProvider`).
- Rôles (spatie/laravel-permission, garde `web`) : `particulier`, `prestataire`, `entrepreneur`,
  `redacteur`, `admin`. Ils sont créés par `RoleSeeder`.

## Sécurité transversale

- Le middleware `App\Http\Middleware\SecurityHeaders` ajoute à chaque réponse : `X-Content-Type-Options`,
  `Referrer-Policy`, `X-Frame-Options` et `Permissions-Policy`.
- Limiteurs de débit nommés (`AppServiceProvider`), valeurs dans **`config/security.php`**
  (`rate_limits`) : `login` (5/min par email + IP, 30/h par email), `two-factor` (5/min, 20/h par
  connexion en cours), `register` (30/h par IP), `forgot-password` (5/h par email, 20/h par IP),
  `reset-password` (10/h par IP), `password-update` et `password-confirm` (5/min par utilisateur),
  `verification` (3/min, 10/h), `api` (60/min), `media-uploads` (20/min), `account-deletion` (6/min).
- **Journal de sécurité** : canal `security` (`storage/logs/security-AAAA-MM-JJ.log`, 30 jours), écrit
  par `App\Support\SecurityLog::record()`. Chaque entrée : `event`, `user_id`, `ip`, `route`, `at`
  (UTC). Jamais d'email (utiliser `SecurityLog::emailHash()`), de nom, de mot de passe, de jeton ni
  de code 2FA. Événements : `auth.login`, `auth.login_failed`, `auth.logout`, `auth.registered`,
  `auth.registration_existing_email`, `auth.email_verified`, `auth.email_verification_failed`,
  `auth.password_reset_requested` (avec `outcome`), `auth.password_reset`, `auth.password_changed`,
  `auth.two_factor_enabled|confirmed|disabled|failed|recovery_codes_regenerated`, `account.deleted`,
  `authorization.denied` (`Gate::after`, avec `ability`, `resource`, `resource_ref`, `status`),
  `rate_limit.exceeded`, `projects.browse`, `projects.view`, `projects.scraping_suspected`.
  Les événements Laravel et Fortify sont journalisés par `App\Listeners\LogSecurityEvents`
  (listeners découverts automatiquement dans `app/Listeners`). Les tests gardent ce journal en
  mémoire (`securityLog()` dans `tests/Pest.php`).
- La réinitialisation du mot de passe supprime toutes les sessions en base de l'utilisateur, et le
  changement de mot de passe toutes sauf la session courante (`App\Support\UserSessions`).
- Les routes Fortify n'ont pas le groupe `api` : `App\Http\Middleware\ThrottleAuthRoutes` (ajouté
  dans `fortify.middleware`) leur applique le limiteur correspondant à leur nom de route.
  `tests/Feature/Security/AuthRateLimitTest.php` vérifie chaque limite.

## Qualité

À lancer depuis `backend/`, avec le PHP de Herd. Tout doit passer avant chaque commit :

```sh
php artisan test             # Pest, SQLite en mémoire
vendor/bin/pint              # style (preset laravel)
vendor/bin/phpstan analyse --memory-limit=1G   # Larastan niveau 6
```

- La limite mémoire par défaut de PHP (128 Mo) ne suffit plus : `phpunit.xml` fixe 512 Mo pour les
  tests, et PHPStan se lance avec `--memory-limit=1G`.
- Les helpers de test partagés (`serviceCategory()`, `projectOwner()`, `projectPayload()`, images…)
  sont dans `tests/Pest.php`. Les requêtes de test envoient `Accept-Language: fr-FR` par défaut
  (`tests/TestCase.php`).

- Les tests simulent l'API Have I Been Pwned avec `Http::fake` (voir `tests/Pest.php`).
- Fais des commits séparés et lisibles. Ne travaille pas directement sur `main` et ne pousse rien sans
  demande explicite.
