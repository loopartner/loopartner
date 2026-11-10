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
  reste déclaré en dernier. `tests/Feature/SchedulerTest.php` vérifie la planification du worker.
- Toutes les dates sont stockées en **UTC** (`app.timezone = UTC`, connexion MySQL en `+00:00`).
  La conversion vers le fuseau de l'utilisateur se fait à l'affichage, côté frontend.

## Architecture modulaire

Le code métier vit dans `backend/app/Modules/<Module>/`. Les modules sont : `Accounts`, `Directory`,
`Events`, `Blog` et `Compliance`.

Chaque module contient :

```
Models/  Http/Controllers/  Http/Requests/  Http/Resources/  Policies/  Actions/  Jobs/  routes.php
```

Et, au besoin : `Enums/` (enums PHP), `Rules/` (règles de validation) et `Support/` (petites classes
utilitaires du module).

- `App\Providers\ModuleServiceProvider` charge automatiquement chaque `routes.php` avec le préfixe
  `/api/v1`, le middleware `api` et `throttle:api`. Pour ajouter un module, il suffit de créer son
  dossier et son `routes.php`.
- **Règle d'isolation** : un module n'utilise jamais directement les modèles d'un autre module
  (`App\Modules\X\Models\*` est interdit depuis `App\Modules\Y`). Pour communiquer entre modules, on
  passe par des Actions publiques, des événements ou des identifiants. Un test d'architecture Pest
  vérifie cette règle (`tests/Arch/ModulesTest.php`).
- **Exception** : `App\Models\User` est le modèle partagé du projet, tous les modules peuvent l'utiliser.
  Il en va de même pour la brique média du noyau (`App\Models\Media` et `App\Support\Media`, voir la
  section « Brique média »). Le noyau, lui, ne dépend jamais d'un module.
- Règles de validation partagées du noyau, dans `app/Rules` (namespace `App\Rules`), utilisables par
  tous les modules : `NoHtml` (refuse le HTML), `CountryCode` (ISO 3166-1 alpha-2) et `CurrencyCode`
  (ISO 4217). Les listes de codes sont statiques (ni package ni extension intl).
- Langues : `App\Support\Locale` (`fr`, `en`). `Locale::fromRequest()` choisit la langue d'une réponse :
  paramètre `locale`, puis en-tête `Accept-Language`, puis `fr`. Une valeur inconnue est ignorée.
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
  l'inscription. Le rôle est attribué par l'Action `CreateAccount`, dans la même transaction.
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
- Comptes locaux créés par `DatabaseSeeder` (environnement `local` uniquement) : `<rôle>@example.com`
  pour chaque rôle, mot de passe `password`, email vérifié.

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

## API et authentification

- Toutes les routes métier sont sous `/api/v1`. Toutes les erreurs sur `api/*` sont rendues en JSON.
- Sanctum fonctionne en **mode SPA** : authentification par cookie de session, sans jeton dans le
  navigateur. Le frontend appelle d'abord `GET /sanctum/csrf-cookie`, puis envoie le header `X-XSRF-TOKEN`.
- Fortify fonctionne en mode headless (`views => false`). Ses routes sont sous `/api/v1/auth` (login,
  logout, register, forgot-password, reset-password, email/verify, user/password, two-factor…).
  - Les liens envoyés par email (réinitialisation du mot de passe) pointent vers `FRONTEND_URL`.
- Le CORS n'autorise que `FRONTEND_URL`, avec les cookies (`supports_credentials`).
- Variables d'environnement : `FRONTEND_URL`, `SANCTUM_STATEFUL_DOMAINS`, `SESSION_DOMAIN`.
- Mots de passe : 12 caractères minimum, et refus des mots de passe présents dans des fuites connues
  (`Password::defaults()` dans `AppServiceProvider`).
- Rôles (spatie/laravel-permission, garde `web`) : `particulier`, `prestataire`, `entrepreneur`,
  `redacteur`, `admin`. Ils sont créés par `RoleSeeder`.

## Sécurité transversale

- Le middleware `App\Http\Middleware\SecurityHeaders` ajoute à chaque réponse : `X-Content-Type-Options`,
  `Referrer-Policy`, `X-Frame-Options` et `Permissions-Policy`.
- Limiteurs de débit nommés (`AppServiceProvider`) :
  - `login` : 5 tentatives par minute, par email et IP ;
  - `two-factor` : 5 tentatives par minute ;
  - `api` : 60 requêtes par minute, par utilisateur ou par IP.
- `DELETE /api/v1/me` (suppression de compte) est en plus limité à 6 requêtes par minute (`throttle:6,1`).

## Qualité

À lancer depuis `backend/`, avec le PHP de Herd. Tout doit passer avant chaque commit :

```sh
php artisan test             # Pest, SQLite en mémoire
vendor/bin/pint              # style (preset laravel)
vendor/bin/phpstan analyse   # Larastan niveau 6
```

- Les tests simulent l'API Have I Been Pwned avec `Http::fake` (voir `tests/Pest.php`).
- Fais des commits séparés et lisibles. Ne travaille pas directement sur `main` et ne pousse rien sans
  demande explicite.
