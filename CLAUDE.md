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
  minute, et le planificateur exécute `queue:work --stop-when-empty`.
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
- Pas d'avatar pour l'instant : il sera ajouté avec le stockage Cloudflare R2.
- Comptes locaux créés par `DatabaseSeeder` (environnement `local` uniquement) : `<rôle>@example.com`
  pour chaque rôle, mot de passe `password`, email vérifié.

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
