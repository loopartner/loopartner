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
- **Limites de débit** sur toutes les routes, plus strictes sur l'authentification, la
  consultation des projets, l'envoi de demandes de mise en relation et de messages, les signalements
  et les blocages (`config/security.php`).
- **Validation stricte des entrées** (FormRequest, texte sans HTML ni caractères invisibles ou de contrôle de direction,
  listes fermées) et requêtes SQL
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

Les événements de la mise en relation y figurent aussi : demande envoyée (type et identifiants),
dépassement de la limite de demandes (signe de spam), blocage et déblocage d'un utilisateur,
signalement (motif et identifiants). **Le contenu des messages, des demandes et des commentaires de
signalement n'y est jamais écrit.**

> **À faire avant l'ouverture au public** : cette journalisation (données collectées, finalité,
> base légale, durée de conservation) doit figurer dans la **politique de confidentialité**.

## Mise en relation et messagerie : données personnelles et durées de conservation

Les demandes de mise en relation et les messages sont des données personnelles. Ils ne sont visibles
que des deux participants (tout autre utilisateur, administrateurs compris, reçoit une erreur 404 :
il n'existe pas encore d'accès de modération). L'API n'expose jamais d'email, de téléphone ni
d'identifiant interne ; le nom d'un porteur de projet n'est révélé au prestataire qu'après
l'acceptation de la demande. Les emails de notification ne contiennent ni le contenu des messages, ni
de nom, ni le titre du projet d'un autre utilisateur.

| Données | Conservation | Ensuite |
|---|---|---|
| Demande en attente sans réponse | 30 jours | passe au statut « expirée » |
| Demande refusée, retirée ou expirée | 12 mois après ce changement de statut | supprimée |
| Demande acceptée et sa conversation | tant que les deux comptes existent | voir ligne suivante |
| Conversation dont un participant a supprimé son compte | 12 mois après la suppression du compte, pour l'autre participant (lecture seule, sans lien avec le compte supprimé) | supprimée avec tous ses messages |
| Copie du contenu signalé et commentaire d'un signalement | 12 mois après le signalement | effacés |
| Signalement clos par la modération | 12 mois après sa clôture | supprimé |

- **Suppression de compte** : les demandes non acceptées de l'utilisateur sont supprimées tout de
  suite ; ses conversations restent lisibles par l'autre participant (intérêt légitime : historique
  des échanges, devis, preuve en cas de litige, RGPD art. 6.1.f et 17.3.e), sans nom ni identifiant du
  compte supprimé, puis sont purgées après la durée ci-dessus ; ses blocages sont supprimés ; les
  signalements qui le concernent sont conservés pour la modération, sans lien avec son compte.
- **Base légale** : exécution du service demandé (art. 6.1.b) pour les demandes et les messages ;
  intérêt légitime (art. 6.1.f) pour la conservation après suppression d'un compte et pour la
  modération des signalements.
- **Réglage** : les durées de 12 mois sont dans `backend/config/security.php` (`retention`), appliquées
  chaque nuit par la tâche planifiée `matching:prune`. Toute modification doit être reportée dans la
  politique de confidentialité.

> **À faire avant l'ouverture au public** : reprendre ce tableau dans la **politique de
> confidentialité**, et prévoir l'outil de modération des signalements (back-office).

## Événements : modération, données personnelles et durées de conservation

- **Validation obligatoire** : un événement proposé par un prestataire, un entrepreneur ou un
  rédacteur n'est jamais public sans la validation d'un admin, et sa modification après publication
  est une révision qui ne remplace la version publiée qu'après validation (lien de connexion et liens
  externes compris). L'admin décide sur la version qu'il a lue (contrôle de version, 409 sinon).
- **Modération** : réservée aux admins dont la double authentification est confirmée **et** dont la
  session a été ouverte avec un code 2FA (403 sinon ; 404 pour les autres rôles). Chaque décision
  (validation, refus, annulation, publication directe d'un admin) est enregistrée dans le journal
  d'audit en base (`admin_audit_logs` : admin, action, élément, version, motif, IP, date), conservé
  **24 mois**, en plus du canal security.
- **Lien de connexion** : chiffré en base, jamais public, jamais dans un email ni dans le journal ;
  visible par l'organisateur, les inscrits et les admins pendant la modération.
- **Inscrits** : l'organisateur ne voit que leur nom et leur type de compte (l'inscrit en est
  prévenu avant de s'inscrire), jamais leur email. Il communique avec eux par des annonces envoyées
  par la plateforme (texte lu sur la page de l'événement, validation par un admin si un lien y figure).
- **Liens** : https uniquement ; adresses IP, hôtes locaux, raccourcisseurs et domaines de
  `DENIED_LINK_DOMAINS` refusés, y compris dans le texte. Le serveur n'ouvre jamais ces liens.

| Données | Conservation | Ensuite |
|---|---|---|
| Inscription à un événement | 12 mois après la fin de l'événement | supprimée (seul le nombre d'inscrits reste) |
| Annonce aux inscrits | 12 mois après la fin de l'événement | supprimée |
| Révision refusée, retirée ou appliquée | 6 mois après la décision | supprimée |
| Événement annulé, ou détaché de son organisateur (compte supprimé) | 12 mois après sa date de fin | supprimé avec sa couverture |
| Journal d'audit des décisions d'administration | 24 mois | supprimé |

- **Suppression de compte** : les inscriptions de l'utilisateur sont supprimées ; ses événements à
  venir sont annulés (les inscrits sont prévenus) puis détachés de son compte (ni identifiant ni nom
  d'organisateur) ; ses autres événements sont supprimés. Les décisions d'un admin supprimé restent
  dans le journal d'audit.
- **Réglage** : durées dans `backend/config/security.php` (`retention`), appliquées chaque nuit par
  `events:prune` et `admin-audit:prune`. Toute modification doit être reportée dans la politique de
  confidentialité.

### Volume d'emails (Resend)

Les emails partent par Resend, dont l'offre de départ a un quota quotidien et mensuel limité. Chaque
inscrit reçoit **au minimum deux emails** : la confirmation d'inscription et le rappel 24 heures avant
le début. S'y ajoutent, selon les cas, la modification importante validée, chaque annonce (5 au plus
par événement) et l'annulation.

- Un événement complet au plafond par défaut (`config/events.php`, `max_registrations` = **300**)
  représente au moins **600 emails**, dont **300 rappels envoyés le même jour**, et jusqu'à
  300 × (2 + 1 + 5 + 1) = 2 700 emails dans le pire cas.
- Plusieurs grands événements le même jour additionnent leurs rappels : vérifier le quota de l'offre
  Resend avant d'augmenter `max_registrations`, et surveiller la file d'attente (`jobs`, `failed_jobs`)
  lors des premiers événements.

> **À faire avant l'ouverture au public** : reprendre ce tableau dans la **politique de
> confidentialité** (inscriptions, nom visible par l'organisateur, durées de conservation).

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
  Elle est déjà exigée pour la modération des événements (compte et session).
- Étendre le journal d'audit (`admin_audit_logs`, en place pour la modération des événements) aux
  futures actions d'administration (back-office, Blog), et aux consultations de contenus signalés.
- Notification « nouvelle connexion » et liste des sessions actives, avec déconnexion à distance.
- Alerte automatique (email ou outil de supervision) sur les avertissements du journal de sécurité
  (`projects.scraping_suspected`, `match_requests.limit_exceeded`, rafales de `auth.login_failed`).
- Back-office de modération des signalements (avec journal d'audit des consultations de contenus
  signalés).
- Détection des coordonnées (email, téléphone, IBAN) partagées dans les messages, pour avertir
  l'utilisateur avant l'envoi.
