# Sécurité de Loopartner

## Signaler une vulnérabilité

Merci de nous aider à protéger les utilisateurs de Loopartner.

- **Canal privé** : écrivez à `securite@loopartner.com`.
- **Contenu utile** : l'endpoint ou la fonctionnalité concernée, les étapes pour reproduire, l'impact
  estimé et, si possible, une preuve de concept minimale.
- **Nos engagements** : accusé de réception sous 3 jours ouvrés, première évaluation sous 10 jours
  ouvrés, puis information à chaque étape jusqu'à la correction. Nous convenons avec vous de la date
  de publication, après le déploiement du correctif.
- **Règles de test** : utilisez uniquement vos propres comptes. N'accédez pas aux données d'autres
  utilisateurs (arrêtez-vous dès la preuve de l'accès), ne dégradez pas le service (pas de déni de
  service ni de test de charge) et n'envoyez pas de spam. Une recherche menée de bonne foi dans ce
  cadre ne fera l'objet d'aucune poursuite de notre part.

## Principes de sécurité du projet

Référentiels : **OWASP API Security Top 10** et **OWASP ASVS niveau 2**. La liste de contrôle
appliquée à chaque tâche de développement est dans `CLAUDE.md` (« Sécurité : liste de contrôle
obligatoire »).

- **Moindre privilège** : chaque endpoint est autorisé au niveau de la fonction (rôle, email
  vérifié, profil publié) et de l'objet (propriétaire). L'objet d'un autre utilisateur donne 404.
  Les rôles `redacteur` et `admin` ne s'obtiennent jamais par l'inscription.
- **Aucune énumération des comptes** : l'inscription (202) et le mot de passe oublié (200) répondent
  de la même façon que l'adresse existe ou non ; les emails partent par la file d'attente.
- **Authentification** : session Sanctum par cookie (`HttpOnly`, `Secure`, `SameSite=Lax`, chiffrée),
  protection CSRF, mots de passe de 12 caractères minimum et refusés s'ils figurent dans des fuites
  connues, double authentification disponible. Changer ou réinitialiser le mot de passe déconnecte
  les autres appareils.
- **Limites de débit** sur toutes les routes, plus strictes sur l'authentification et la
  consultation des projets (`config/security.php`).
- **Validation stricte des entrées** (FormRequest, texte sans HTML, listes fermées) et requêtes SQL
  paramétrées. Images vérifiées sur leur contenu réel puis réencodées en WebP sans métadonnées.
- **Exposition minimale** : les réponses listent chaque champ, sans identifiant interne ni donnée
  personnelle inutile. Les projets publiés n'exposent ni le nom ni l'email de leur porteur.
- **En-têtes HTTP** : CSP `default-src 'none'`, `X-Frame-Options`, `nosniff`, COOP, CORP,
  `Referrer-Policy`, `Permissions-Policy`, et HSTS en production.
- **Journalisation** des événements de sécurité, sans secret ni donnée personnelle inutile (voir
  ci-dessous).
- **Dépendances** : `composer security` (composer audit) avant chaque commit et chaque mise en
  production. Aucun paquet n'est ajouté sans revue.

## Journal de sécurité et données personnelles

Le canal de journalisation `security` (`backend/storage/logs/security-AAAA-MM-JJ.log`) enregistre
les connexions réussies et échouées, les déconnexions, les inscriptions, les demandes et
réinitialisations de mot de passe, les changements de mot de passe, les événements de double
authentification, les suppressions de compte, les refus d'autorisation, les blocages par limite de
débit et les consultations de projets par les prestataires.

Chaque entrée contient : le type d'événement, l'identifiant interne de l'utilisateur s'il est connu,
**l'adresse IP**, la date et la route. Elle ne contient jamais de mot de passe, de jeton, de code 2FA,
de nom ni d'email en clair (une empreinte HMAC de l'email sert à relier les tentatives visant une même
adresse).

- **Finalité** : détecter les attaques (force brute, collecte massive des projets, comptes multiples
  utilisés par une même personne) et enquêter sur un incident.
- **Base légale** : intérêt légitime (sécurité du service, RGPD art. 6.1.f).
- **Conservation** : 30 jours (rotation quotidienne, `SECURITY_LOG_DAYS`), puis suppression.
- **Accès** : réservé aux administrateurs techniques.

> **À faire avant l'ouverture au public** : cette journalisation (données collectées, finalité,
> base légale, durée de conservation) doit figurer dans la **politique de confidentialité**.

## Vérifications avant chaque mise en production

Depuis `backend/`, sur le serveur de production :

1. **Dépendances sans outils de développement** :
   `composer install --no-dev --optimize-autoloader`, puis `composer audit` (aucune vulnérabilité).
2. **Configuration** : `php artisan config:cache`, puis **`php artisan security:check-config`**. Le
   déploiement s'arrête si la commande échoue. Lire aussi ses avertissements (`TRUSTED_PROXIES`,
   php.ini).
3. **Base de données** : `php artisan migrate --force`, puis uniquement les données de référence :
   `php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force`.
   Ne jamais lancer `db:seed` sans `--class` en production, ni définir `SEED_DEMO_DATA`.
4. **Tâche cron** du planificateur en place (`php artisan schedule:run` chaque minute) : sans elle,
   aucun email (vérification, réinitialisation) ne part.
5. **Proxy et HTTPS** : si l'API passe derrière Cloudflare ou un autre proxy, `TRUSTED_PROXIES`
   contient ses plages d'adresses (sinon l'IP des clients et le HTTPS sont mal détectés). Le site
   n'est accessible qu'en HTTPS (redirection HTTP vers HTTPS au niveau de l'hébergement).
6. **PHP** : `upload_max_filesize` et `post_max_size` d'au moins 8 Mo, GD avec WebP, extensions
   `exif` et `fileinfo`, `zend.exception_ignore_args = On`, `expose_php = Off`.
7. **Fichiers** : `backend/.env` non accessible depuis le web (seul `backend/public` est exposé),
   `APP_KEY` propre à la production et sauvegardée hors du serveur.
8. **Contrôle rapide après déploiement** : `GET /up` répond 200, une réponse de l'API porte bien
   `Strict-Transport-Security` et `Content-Security-Policy`, et `/` ne renvoie pas de page HTML.

## Chantiers de sécurité à venir

- Double authentification **obligatoire** pour les rôles `admin` et `redacteur` (avec le back-office).
- Journal d'audit des actions d'administration.
- Notification « nouvelle connexion » et liste des sessions actives, avec déconnexion à distance.
- Alerte automatique (email ou outil de supervision) sur les avertissements du journal de sécurité
  (`projects.scraping_suspected`, rafales de `auth.login_failed`).
