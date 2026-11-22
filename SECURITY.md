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

## Vitrine publique des projets (décisions 71 et 80)

`GET /public/projects` et `GET /public/projects/{uuid}` sont ouverts sans compte. Ils montrent
le titre, la description, la catégorie, le budget, le pays, le travail à distance et la date de
publication d'un projet consultable, après la fenêtre d'accès anticipé ; jamais la ville, la date
de début, ni le nom, le type de compte ou le pays du porteur. La description est publique depuis
la décision 80 : le parcours de publication avertit le porteur de ne pas y écrire ses
coordonnées et signale un téléphone, un email ou un lien repéré dans le texte (sans blocage :
risque restant, un porteur peut passer outre). La proposition reste réservée aux prestataires
connectés (consultation journalisée, limiteur `projects-browse`). Limiteur par IP
`public-projects` (30 par minute, 1 000 par jour) contre la collecte massive. À reporter dans
les CGU et la politique de confidentialité (juriste).

## Mise en relation et messagerie : données personnelles et durées de conservation

Les demandes de mise en relation et les messages sont des données personnelles. Ils ne sont visibles
que des deux participants (tout autre utilisateur, administrateurs compris, reçoit une erreur 404).
Un message ou une demande signalés sont copiés pour la modération (module Moderation, voir plus
bas) ; c'est le seul accès de l'équipe à leur contenu. L'API n'expose jamais d'email, de téléphone ni
d'identifiant interne ; le nom d'un porteur de projet n'est révélé au prestataire qu'après
l'acceptation de la demande. Les emails de notification ne contiennent ni le contenu des messages, ni
de nom, ni le titre du projet d'un autre utilisateur.

| Données | Conservation | Ensuite |
|---|---|---|
| Demande en attente sans réponse | 30 jours | passe au statut « expirée » |
| Demande refusée, retirée ou expirée | 12 mois après ce changement de statut | supprimée |
| Demande acceptée et sa conversation | tant que les deux comptes existent | voir ligne suivante |
| Conversation dont un participant a supprimé son compte | 12 mois après la suppression du compte, pour l'autre participant (lecture seule, sans lien avec le compte supprimé) | supprimée avec tous ses messages |
| Signalement d'un message ou d'une demande | voir « Signalements et modération (DSA) » | — |

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
> confidentialité**.

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

## Blog : modération, référencement, données personnelles et durées de conservation

- **Validation obligatoire** : un article d'un prestataire ou d'un entrepreneur, premium ou non,
  n'est jamais public sans la validation de l'équipe (rédacteurs et admins), et sa modification
  après publication est une révision qui ne remplace la version publiée qu'après validation. Le
  modérateur décide sur la version qu'il a lue (409 sinon).
- **Modération** : rédacteurs et admins dont la double authentification est confirmée **et** dont
  la session a été ouverte avec un code 2FA (403 sinon ; 404 pour les autres rôles). **Personne ne
  modère son propre article** (403, journalisé). Seul un admin dépublie un article de la
  rédaction. La publication directe d'un article de la rédaction exige aussi la 2FA de session.
  Chaque décision est enregistrée dans le journal d'audit (24 mois) ; le canal security ne contient
  que des identifiants, jamais le contenu d'un article ni un motif.
- **Contenu** : le corps d'un article est un document JSON à liste fermée d'éléments, reconstruit
  par le serveur ; aucun HTML n'est stocké ni renvoyé. Les images sont uniquement des médias de
  la plateforme (réencodés en WebP, sans métadonnées) : aucune image externe ni pixel de suivi.
  Les liens suivent les règles des liens externes (https, ni IP, ni raccourcisseur, ni domaine
  refusé) et la modération voit les domaines nouveaux et les noms internationalisés.
- **Liens payants** : les liens de tout auteur extérieur à l'équipe, **premium compris**, sont
  marqués `rel="nofollow ugc"`. Le statut premium est payé pour publier : un lien suivi serait un
  lien payant au sens des moteurs de recherche et exposerait le site à une pénalité. Ce marquage
  n'est donc jamais un avantage premium possible.
- **Statut premium** : attribué ou retiré uniquement par un admin avec 2FA de session, avec
  motif dans le journal d'audit ; aucun endpoint ne permet à un utilisateur de se l'attribuer.

| Données | Conservation | Ensuite |
|---|---|---|
| Révision validée, refusée, retirée ou remplacée | 6 mois après la décision | supprimée (la version publiée et le journal d'audit restent) |
| Brouillon jamais soumis | 12 mois sans modification | supprimé avec ses images |
| Article refusé ou dépublié | 12 mois sans modification | supprimé avec ses images |
| Décompte du quota gratuit (première soumission de chaque article) | tant que le compte existe | supprimé avec le compte |
| Slug d'un article supprimé (réponse 410, slug non réattribué) | 24 mois | supprimé |
| Périodes premium | tant que le compte existe | supprimées avec le compte |

- **Suppression de compte** : les articles d'un prestataire ou d'un entrepreneur sont
  **supprimés**, publiés compris, avec leurs images (un article signé est une donnée personnelle,
  et la suppression du compte vaut demande d'effacement, RGPD art. 17). Les articles de l'équipe,
  signés « la rédaction » et écrits pour la plateforme, sont détachés du compte et conservés.
  **Conserver un jour les articles publiés d'un auteur extérieur après la suppression de son
  compte exigerait une clause de cession de droits (licence d'exploitation) dans les CGU**,
  acceptée avant la publication, et une anonymisation réelle de l'article.
- **Réglage** : durées dans `backend/config/security.php` (`retention`), appliquées chaque nuit par
  `blog:prune`. Toute modification doit être reportée dans la politique de confidentialité.

> **À faire avant l'ouverture au public** : reprendre ce tableau dans la **politique de
> confidentialité**, et prévoir dans les CGU les règles de publication des articles (contenu
> autorisé, liens, modération, retrait).

## Suivi administratif : règles vérifiées, données privées et durées de conservation

- **Règles vérifiées par un professionnel** : les statuts, échéances, seuils et taux sont des
  données (jamais du code), montrées aux utilisateurs seulement une fois vérifiées. La
  vérification est faite par un professionnel (expert-comptable externe, sans compte admin) ;
  l'admin qui l'enregistre doit saisir une **note de vérification obligatoire** (au moins
  30 caractères : qui a vérifié, quand, sur quel document ou quelle source), gardée sur la règle et
  dans le journal d'audit (24 mois). Une règle vérifiée n'est jamais modifiée sur place : sa
  modification est une nouvelle version en brouillon, vérifiée à son tour. Les brouillons importés
  (`compliance:import-rules`) ne contiennent que des valeurs lues sur une page officielle, avec
  leur source et leur date de lecture ; le reste est « à compléter ».
- **Principe des quatre yeux** : désactivé par défaut tant que l'équipe ne compte qu'un admin
  (`COMPLIANCE_REQUIRE_DISTINCT_VERIFIER=false`). À activer dès que deux admins peuvent se relire
  (`COMPLIANCE_REQUIRE_DISTINCT_VERIFIER=true`, puis `php artisan config:cache`) : l'admin qui a
  modifié une règle en dernier ne pourra plus la vérifier. `security:check-config` avertit si le
  réglage reste désactivé alors que deux admins ont la double authentification.
- **Responsabilité** : l'outil est une aide à l'organisation et **ne remplace pas un
  expert-comptable**. Chaque réponse de l'API (champ `disclaimer`), chaque email et l'abonnement
  au calendrier le rappellent ; l'estimation des cotisations est présentée comme une estimation.
- **Données strictement privées** : toutes les données d'une entreprise ne sont visibles que de
  leur propriétaire (404 pour tout autre utilisateur, **administrateurs compris** : les admins ne
  gèrent que les règles). Le journal security n'en contient jamais : ni numéro d'entreprise, ni
  nom, ni montant, ni note, ni jeton.
- **Chiffrement en base** : nom de l'entreprise, SIREN/SIRET, numéro de TVA, montants du chiffre
  d'affaires, titres et notes saisis par l'utilisateur sont chiffrés par l'application avec
  `APP_KEY` (une copie de la base ne les révèle pas). Les emails du suivi administratif sont aussi
  chiffrés dans la file d'attente.
- **Abonnement au calendrier** : URL privée à jeton (56 caractères, dont un secret d'environ
  238 bits) ; seule l'empreinte HMAC du secret est stockée, la comparaison se fait en temps
  constant, l'URL est limitée en débit, révocable et régénérable, et ne donne que les titres et
  dates des échéances. Un jeton invalide est journalisé au plus une fois par heure et par IP,
  sans le jeton.

| Données | Conservation | Ensuite |
|---|---|---|
| Entreprise, profil, réglages, abonnement au calendrier | tant que le compte existe | supprimés avec le compte |
| Échéance passée (et ses tâches) | 36 mois après sa date | supprimée |
| Chiffre d'affaires | 6 années civiles complètes | supprimé par année entière le 1er janvier (annoncé dans le récapitulatif du 1er décembre) |
| Alerte de seuil, parcours terminé, tâche libre cochée | 36 mois | supprimés |
| Trace d'un récapitulatif envoyé | 3 mois | supprimée |

- **Suppression de compte** : toutes les données du suivi administratif sont supprimées.
- **Export** : l'utilisateur peut télécharger toutes ses données à tout moment
  (`GET /me/compliance/export`, JSON) ; les durées de conservation et la prochaine purge sont
  indiquées par l'API.
- **Réglage** : durées dans `backend/config/security.php` (`retention.compliance_*`), appliquées
  chaque nuit par `compliance:prune`. Toute modification doit être reportée dans la politique de
  confidentialité.

### `APP_KEY` : sauvegarde et rotation

`APP_KEY` chiffre les sessions, les secrets 2FA, le lien de connexion des événements et les données
du suivi administratif (SIRET, montants…). **Sans elle, ces données sont définitivement
illisibles.**

- **Sauvegarde** : garder `APP_KEY` de production hors du serveur (coffre de mots de passe de
  l'équipe, accès restreint), à côté des sauvegardes de la base, mais jamais dans le même fichier
  ni le même stockage.
- **Rotation** (fuite suspectée, départ d'une personne qui la connaissait) : générer une nouvelle
  clé, la mettre dans `APP_KEY` et placer l'ancienne dans `APP_PREVIOUS_KEYS` (liste séparée par
  des virgules), puis `php artisan config:cache`. Laravel déchiffre alors avec l'ancienne clé les
  valeurs qu'elle a chiffrées et chiffre les nouvelles avec la nouvelle. Les sessions ouvertes
  restent valides. Les empreintes HMAC calculées avec `APP_KEY` (abonnements au calendrier,
  empreintes d'email du journal) ne se recalculent pas : les URL d'abonnement deviennent invalides
  et doivent être régénérées par les utilisateurs. Ne retirer l'ancienne clé de
  `APP_PREVIOUS_KEYS` qu'après avoir rechiffré les données (commande à prévoir avant la première
  rotation réelle).

> **À faire avant l'ouverture au public** : reprendre le tableau ci-dessus dans la **politique de
> confidentialité**, prévoir dans les CGU la limite de responsabilité du suivi administratif, et
> faire vérifier les règles françaises 2027 par un expert-comptable (aucune n'est vérifiée
> aujourd'hui : le module n'est proposé à aucun utilisateur tant que ce n'est pas fait).

## Consentements, notifications et double authentification de l'équipe

- **CGU et politique de confidentialité versionnées** : chaque version est immuable (empreinte
  SHA-256 du texte) ; l'inscription exige l'acceptation des versions en vigueur, et une nouvelle
  version bloque l'utilisation (403 `consent_required`) jusqu'à son acceptation, sauf lecture des
  documents, acceptation, déconnexion et suppression du compte. Une version peut être publiée à
  l'avance pour informer les utilisateurs avant son entrée en vigueur.
- **Preuve du consentement** : date (UTC) et adresse IP de chaque acceptation, conservées tant que
  le compte existe, supprimées avec lui. Base légale : exécution du contrat (art. 6.1.b) et
  obligation de pouvoir démontrer le consentement à la politique.
- **Préférences de notifications** : l'utilisateur coupe les emails facultatifs ; les emails de
  sécurité du compte, d'information sur un engagement (inscription à un événement) et les
  décisions de modération motivées partent toujours.
- **Équipe** : la double authentification est obligatoire pour les rôles `admin` et `redacteur`
  (fonctions d'administration refusées sans 2FA confirmée et session ouverte avec un code ;
  désactivation refusée et journalisée).

| Données | Conservation | Ensuite |
|---|---|---|
| Acceptation d'une version des CGU ou de la politique (date, IP) | tant que le compte existe | supprimée avec le compte |
| Versions des documents légaux | toujours (preuve du texte accepté) | jamais supprimées |
| Préférences de notifications | tant que le compte existe | supprimées avec le compte |

> **À faire avant l'ouverture au public** : faire rédiger les CGU et la politique de
> confidentialité (reprenant les tableaux de ce fichier), puis les publier avec
> `php artisan legal:publish`. Sans elles, l'inscription est fermée (503) et
> `security:check-config` échoue.

## Signalements et modération (DSA) : données personnelles et durées de conservation

Module Moderation (lot 2, décisions 21 à 31 ; règles et textes à faire valider par le juriste :
`docs/v1/moderation-rules.md`).

- **Signalement ouvert à tous** (règlement sur les services numériques, art. 16), avec ou sans
  compte. Sans compte, le signalant donne son nom et son email (facultatifs pour les abus sexuels
  sur mineurs) ; il suit son signalement avec un **jeton secret** de 48 caractères, remis une
  fois et renouvelé à chaque email, dont seule l'empreinte HMAC est stockée, et que le frontend
  porte dans le fragment de l'URL (jamais envoyé aux serveurs ni écrit dans un journal).
- **Abus** : limites par IP, par compte et par email (`moderation-notices`), champ piège, pas de
  captcha (décision 25) ; corps des requêtes limité à 32 Ko.
- **Chiffrement en base** : nom et email du signalant, explication, précision sur l'emplacement,
  copie du contenu signalé, faits d'une décision, texte d'un recours. Les emails du module sont
  aussi chiffrés dans la file d'attente.
- **Copie du contenu** : uniquement textuelle. Pour un signalement d'abus sexuels sur mineurs,
  aucune image n'est copiée ni réaffichée dans l'administration (décision 31).
- **Confidentialité du signalant** : son identité n'est jamais communiquée à l'auteur du contenu
  (décision 29) ; l'administration voit « compte » ou « sans compte » et le nom donné, jamais
  l'email. Les emails au signalant ne contiennent ni les faits ni le titre du contenu.
- **Accès** : traitement réservé aux admins avec double authentification et session ouverte avec
  un code (404 pour les autres rôles, rédacteurs compris) ; un admin ne décide jamais sur son
  propre contenu ; chaque décision, levée, recours, transmission aux autorités **et chaque
  consultation d'un contenu signalé** est inscrite au journal d'audit. Le canal security ne
  contient que des identifiants (jamais l'identité, l'explication, la copie, les faits ni le
  texte d'un recours).
- **Signalements urgents** (abus sexuels sur mineurs, violence ou terrorisme) : alerte immédiate
  aux admins, sans aucun contenu ; objectif de traitement de 24 heures. La transmission aux
  autorités (art. 18) est faite par un admin selon la procédure de `moderation-rules.md` ; rien
  n'est envoyé automatiquement.

| Données | Conservation | Ensuite |
|---|---|---|
| Identité et email du signalant, explication, précision, copie du contenu, jeton de suivi | 12 mois après la clôture du signalement (ou sa réception s'il est encore ouvert) | effacés |
| Exposé des motifs, recours, signalements traités | 24 mois après la fin du délai de recours (6 mois) ou la levée, la plus tardive | supprimés ; une restriction en vigueur garde son exposé des motifs |
| Signalement classé sans décision | 24 mois après sa clôture | supprimé |
| Journal d'audit des décisions et des consultations | 24 mois | supprimé |

- **Suppression de compte** : les signalements envoyés et ceux qui visent ses contenus sont
  conservés pour la modération, sans lien avec le compte ; ses recours sont supprimés.
- **Base légale proposée** (à faire valider par le juriste) : obligation légale (RGPD art. 6.1.c)
  pour le traitement des signalements et l'exposé des motifs ; intérêt légitime (art. 6.1.f) pour
  leur conservation et le recours interne volontaire.
- **Réglage** : durées dans `backend/config/security.php` (`retention.moderation_*`), appliquées
  chaque nuit par `moderation:prune`.

> **À faire avant l'ouverture au public** : faire valider `docs/v1/moderation-rules.md` par le
> juriste ; rédiger les clauses de modération des CGU (art. 14) et reporter leur numéro dans
> `config/moderation.php` (`terms_clauses`, sinon `security:check-config` échoue) ; renseigner
> `MODERATION_CONTACT_EMAIL` et `MODERATION_AUTHORITIES_EMAIL` ; reprendre ce tableau dans la
> politique de confidentialité.

## Marketplace : offres, devis, données personnelles et durées de conservation

Module Marketplace (lot 3, décisions 36 à 50 ; textes et points à faire valider :
`docs/v1/marketplace-rules.md`).

- **Identité professionnelle** des prestataires (dénomination, SIREN, adresse, téléphone, email,
  assurance, médiateur) : **chiffrée en base** (`APP_KEY`), jamais publique en entier (l'offre
  n'affiche que la qualité déclarée, la dénomination et le SIREN), copiée dans chaque devis
  envoyé (mentions obligatoires) et visible du seul client de ce devis. Le SIREN est contrôlé dans
  sa forme seulement.
- **Devis** : visibles de leurs deux parties seulement (404 pour tout autre utilisateur,
  administrateurs compris). Le contenu est figé à l'envoi avec une empreinte SHA-256 ; le client
  n'accepte que le contenu qu'il a lu (409 sinon). Preuve d'acceptation : date, adresse IP, cases
  cochées. Identités de l'émetteur et du client chiffrées en base.
- **Taux de TVA** : jamais dans le code ; règles vérifiées par l'expert-comptable (module
  Compliance), copiées dans chaque ligne à l'envoi. Sans taux vérifié, aucun devis possible.
- **PDF** : générés en mémoire par `dompdf/dompdf` (version fixée dans `composer.lock`), sans
  ressource distante, sans PHP ni JavaScript, à partir d'un HTML construit par l'application dont
  tout texte saisi est échappé ; jamais enregistrés ; `Cache-Control: private, no-store`. Le PDF
  du devis accepté est joint à l'email de confirmation des deux parties (support durable).
- **Logo Premium figé** (décision 51) : copie PNG réduite gardée dans la ligne du devis
  (256 Ko au plus), intégrée au seul PDF des deux parties, sans URL ni fichier, jamais renvoyée
  par l'API ni écrite dans le journal ; supprimée avec le devis (même conservation).
- **Emails** : numéro du devis seulement, ni montant, ni ligne, ni nom, ni titre de projet.
- **Signalement** : offres (formulaire public) et devis (par le client) rejoignent le module
  Moderation ; un devis masqué n'est plus lisible, acceptable ni téléchargeable par le client.
- **Journal security** : uuids, version, motif en liste fermée et fonctionnalité Premium
  seulement ; jamais une identité professionnelle, un montant, une ligne, un titre, un
  commentaire ni une empreinte (`tests/Feature/Security/MarketplacePrivacyTest.php`).
- **Limites de débit** : `seller-profile-writes`, `offer-writes`, `quote-writes`, `quote-sends`,
  `quote-decisions`, `quote-pdfs`, `quote-comparisons` (`config/security.php`) ; corps des
  requêtes de devis limité à 128 Ko.

| Données | Conservation | Ensuite |
|---|---|---|
| Identité professionnelle, offres | tant que le compte existe | supprimées avec le compte |
| Brouillon de devis | 12 mois sans modification | supprimé |
| Devis refusé, retiré, expiré ou remplacé | 12 mois après ce changement | supprimé |
| Devis accepté et preuve d'acceptation (date, IP, cases cochées) | tant que la conversation existe ; 12 mois après la suppression d'un des deux comptes | supprimé avec la conversation |

- **Suppression de compte** : offres, identité professionnelle et devis non acceptés sont
  supprimés ; un devis accepté reste lisible par l'autre partie (c'est son contrat), sans lien
  avec le compte supprimé, jusqu'à la purge de la conversation.
- **Réglage** : durées dans `backend/config/security.php` (`retention.marketplace_*`),
  appliquées chaque nuit par `marketplace:prune`.

> **À faire avant l'ouverture au public** : faire valider `docs/v1/marketplace-rules.md` (juriste
> et expert-comptable) ; faire vérifier les taux de TVA 2027 ; rédiger la rubrique d'information
> de la place de marché et les clauses des CGU (accès anticipé, Loopartner non partie au contrat) ;
> reprendre ce tableau dans la politique de confidentialité.

## Rendez-vous et agenda : données personnelles et durées de conservation

Module Appointments et agenda unifié (lot 4, décisions 52 à 59 ; sources, textes et points à
faire valider : `docs/v1/appointments-rules.md`).

- **Visibilité** : un rendez-vous n'est visible que de ses deux parties (404 pour tout autre
  utilisateur, **administrateurs compris**). Le nom du client n'est montré au prestataire qu'une
  fois la demande de mise en relation acceptée ; le lieu (adresse, téléphone, lien de visio)
  seulement une fois le rendez-vous confirmé. Aucune réponse ne contient d'email, de téléphone
  d'un compte ni d'identifiant interne.
- **Chiffrement en base** (`APP_KEY`) : lieu des rendez-vous, adresse ou lien saisis sur les types
  de rendez-vous, textes libres (motif, messages d'annulation, de refus, de report).
- **Double réservation** : verrou de la ligne du calendrier du prestataire pendant la
  vérification, puis index unique sur les créneaux retenus (409 `slot_unavailable`, journalisé
  `appointments.slot_conflict`).
- **Emails** : titre du type de rendez-vous et date seulement, chiffrés dans la file ; le `.ics`
  joint à la confirmation ne contient que le titre et les dates. Rappels dans une catégorie
  facultative.
- **Abonnement à l'agenda** : URL privée à jeton (même mécanisme que l'abonnement du suivi
  administratif : sélecteur public, secret dont seule l'empreinte HMAC est stockée, comparaison
  en temps constant), révocable et régénérable, limitée en débit ; **titres et dates seulement**
  (ni lieu, ni lien, ni note, ni nom) ; jeton invalide journalisé au plus une fois par heure et par
  IP, jamais le jeton.
- **Note libre** : 500 caractères au plus, avertissement près du champ (pas de donnée de santé ni
  d'autre donnée sensible), signalable par l'autre partie.
- **Journal security** : uuids, statut, motif en liste fermée, fonctionnalité Premium ; jamais une
  note, un lieu, un téléphone, un lien de visio, un nom ou un titre de rendez-vous
  (`tests/Feature/Security/AppointmentsPrivacyTest.php`).
- **Limites de débit** : `appointment-settings-writes`, `appointment-slots`,
  `appointment-public-slots` (par IP), `appointment-bookings`, `appointment-decisions`,
  `appointment-ics`, `agenda`, `agenda-feed-manage`, `agenda-feed` (`config/security.php`) ; corps
  des requêtes limité à 32 Ko ; la réservation sur la page publique compte aussi dans
  `match-requests`.

| Données | Conservation | Ensuite |
|---|---|---|
| Lieu d'un rendez-vous (adresse, téléphone, lien de visio) et textes libres | 3 mois après la fin du rendez-vous ou sa clôture (refus, annulation, expiration) | effacés (le rendez-vous reste) |
| Rendez-vous refusé, annulé ou expiré | 12 mois après la clôture | supprimé |
| Rendez-vous passé (dates, type, statut) | 24 mois après sa fin, ou avant avec sa demande de mise en relation | supprimé |
| Trace d'un rappel envoyé, exception de disponibilité passée | 3 mois | supprimées |
| Calendrier, types, plages, abonnement à l'agenda | tant que le compte existe | supprimés avec le compte |

- **Suppression de compte** : rendez-vous à venir annulés (l'autre partie est prévenue
  sobrement), lieu donné par la personne effacé, calendrier, types, plages, exceptions et
  abonnement supprimés ; rendez-vous passés et textes déjà écrits conservés pour l'autre partie,
  sans lien avec le compte, jusqu'à leur durée de conservation.
- **Base légale proposée** (à faire valider par le juriste) : exécution du service demandé
  (art. 6.1.b) ; intérêt légitime (art. 6.1.f) pour la conservation après la date et après la
  suppression d'un compte.
- **Réglage** : durées dans `backend/config/security.php` (`retention.appointment_details_months`,
  `closed_appointments_months`, `past_appointments_months`, `appointment_reminders_months`,
  `past_availability_exceptions_months`), appliquées chaque nuit par `appointments:prune`.

### Inventaire pour l'export général des données (lot 11)

L'export général (droit d'accès et portabilité, RGPD art. 15 et 20) est prévu au lot 11
(décision 59). Données du lot 4 rattachées à un utilisateur :

| Table | Rattachement | Contenu personnel | Repris par |
|---|---|---|---|
| `appointment_calendars` | `user_id` (unique) | réglages du calendrier | `AppointmentsPersonalData` |
| `appointment_types` | calendrier | titre, description, lieu (chiffré) | `AppointmentsPersonalData` |
| `appointment_availability_rules`, `appointment_availability_exceptions` | calendrier | plages, exceptions | `AppointmentsPersonalData` |
| `appointments` | `client_id`, `provider_id` (indexés) | dates, type, statut, lieu donné (chiffré) | `AppointmentsPersonalData` |
| `appointment_notes` | `author_id` | textes libres (chiffrés) | `AppointmentsPersonalData` (ses propres textes) |
| `appointment_reminders` | rendez-vous | traces techniques des rappels | non repris (aucune donnée fournie) |
| `appointment_slot_holds` | rendez-vous | créneaux retenus | non repris (technique) |
| `agenda_feeds` | `user_id` (unique) | empreinte du jeton | non repris (secret) ; l'état sera indiqué |

> **À faire avant l'ouverture au public** : faire valider `docs/v1/appointments-rules.md` par le
> juriste (tableau de la section 7) ; reprendre le tableau ci-dessus dans la politique de
> confidentialité ; prévoir dans les CGU la page de réservation Premium et l'annulation libre.

## Workspaces, copilote et IA : données personnelles et durées de conservation

Module Workspaces, brique d'IA et parcours d'accueil du noyau (lot 5, décisions 60 à 70 ;
sources, contenu exact envoyé à l'IA, textes et points à faire valider :
`docs/v1/workspaces-rules.md`).

- **Workspaces privés** : visibles de leur seul propriétaire (404 pour tout autre utilisateur,
  **administrateurs compris**) ; aucune route publique. Titres, descriptions, notes, cahiers des
  charges et réponses du copilote sont **chiffrés en base** (`APP_KEY`). Un texte ne devient
  visible d'autrui que si l'utilisateur crée lui-même un brouillon Directory puis le publie (il
  est alors signalable comme tout projet).
- **IA désactivée par défaut** (`AI_DRIVER=disabled`). Fournisseur retenu : **Mistral AI,
  endpoint régional de l'UE** (`https://api.eu.mistral.ai`) ; le pilote refuse tout autre hôte.
  Avant l'activation, l'exploitant désactive l'opt-out d'entraînement, demande la conservation
  nulle (ZDR) et renseigne la clé (étapes : `workspaces-rules.md`, section 7).
- **L'IA ne doit pas être activée sur l'hébergement mutualisé** : l'appel est synchrone (45 s au
  plus) ; `security:check-config` échoue si `max_execution_time` est inférieur à `AI_TIMEOUT`
  + 15 s, et l'API refuse l'appel (503).
- **Contenu envoyé** : liste blanche (titre et description du workspace **expurgés** des emails,
  téléphones, IBAN, liens et numéros d'entreprise ; pays, budget, mois de début, titres des
  étapes, type de compte, catégories publiques). **Jamais** le nom, l'email, un identifiant, la
  ville, les notes ni une donnée d'un autre utilisateur. L'utilisateur voit le contenu exact avant
  l'envoi (prévisualisation) et doit avoir lu l'information sur l'IA (version). Email vérifié
  exigé pour toute génération.
- **Réponse de l'IA = donnée non fiable** : schéma JSON imposé, puis nettoyage (HTML écarté,
  caractères invisibles et liens retirés, catégories actives seulement, longueurs bornées) ;
  gardée comme suggestion, appliquée seulement par l'utilisateur ; marquée `ai_generated`.
- **Coûts** : plafonds par utilisateur (0,50 $ par mois) et globaux (50 $ par mois, 5 $ par jour)
  réservés sous verrou avant chaque appel ; alerte des admins à 80 % et 100 % ; suivi sans
  identité ni contenu (`GET /admin/ai/usage`).
- **Journaux** : le canal security et la table `ai_usages` ne contiennent **jamais** le contenu
  envoyé ni la réponse (fonctionnalité, modèle, jetons, coût, statut, uuids seulement ;
  `WorkspacesPrivacyTest`, `AiGatewayTest`). La clé d'API n'apparaît dans aucune exception ni
  aucun journal.

| Données | Conservation | Ensuite |
|---|---|---|
| Workspace, objectifs, étapes, tâches, cahiers des charges | tant que le compte existe | supprimés avec le compte |
| Suggestion du copilote (réponse de l'IA) | 90 jours après sa génération | supprimée (`workspaces:prune`) |
| Décompte des générations (type, date) | tant que le compte existe (quota gratuit à vie) | supprimé avec le compte |
| Journal des appels à l'IA (sans contenu) | 13 mois | supprimé (`ai:prune`) ; détaché du compte dès sa suppression |
| Totaux par jour, fonctionnalité et modèle | sans limite | aucune donnée personnelle |
| Choix du parcours d'accueil | tant que le compte existe | supprimés avec le compte |
| Chez Mistral AI | 30 jours (surveillance des abus), aucune avec la ZDR | supprimés par le fournisseur |

- **Suppression de compte** : workspaces, contenu, suggestions, décompte, compteurs de coût de
  l'utilisateur et choix du parcours supprimés ; journal des appels détaché.
- **Base légale proposée** (à faire valider par le juriste, J6) : exécution du contrat
  (fonctionnalité demandée et lancée par l'utilisateur). AIPD recommandée avant l'activation (J7).
- **Réglage** : durées dans `backend/config/security.php` (`retention.copilot_suggestions_days`,
  `retention.ai_usages_months`).

### Inventaire pour l'export général des données (lot 11)

| Table | Rattachement | Contenu personnel | Repris par |
|---|---|---|---|
| `workspaces` | `user_id` | titre, description (chiffrés), pays, budget, dates | `WorkspacesPersonalData` |
| `workspace_goals`, `workspace_steps`, `workspace_tasks` | workspace | titres, descriptions, notes (chiffrés), échéances | `WorkspacesPersonalData` |
| `workspace_specifications` | workspace | cahiers des charges (chiffrés) | `WorkspacesPersonalData` |
| `workspace_copilot_suggestions` | workspace | réponses du copilote (chiffrées) | `WorkspacesPersonalData` |
| `workspace_copilot_entries` | `user_id` | type et date des générations | `WorkspacesPersonalData` |
| `ai_usages` | `user_id` (null après suppression) | fonctionnalité, modèle, jetons, coût, statut | `AiUsagePersonalData` |
| `ai_budgets` (portée `user:<id>`) | `user_id` | dépense du mois | non repris (technique) |
| `onboarding_states` | `user_id` (unique) | étapes passées, parcours masqué | `AiUsagePersonalData` |

> **À faire avant l'ouverture au public** : faire valider les points J1 à J11 de
> `docs/v1/workspaces-rules.md` ; réaliser l'AIPD ; reprendre le tableau ci-dessus et le
> sous-traitant (Mistral AI, traitement dans l'UE) dans la politique de confidentialité ; tracer
> la formation de l'équipe à l'IA (règlement sur l'IA, art. 4) ; activer l'IA seulement hors de
> l'hébergement mutualisé.

## Vérifications avant chaque mise en production

Depuis `backend/`, sur le serveur de production :

1. **Dépendances sans outils de développement** :
   `composer install --no-dev --optimize-autoloader`, puis `composer audit` (aucune vulnérabilité).
2. **Configuration** : `php artisan config:cache`, puis **`php artisan security:check-config`**. Le
   déploiement s'arrête si la commande échoue. Lire aussi ses avertissements (`TRUSTED_PROXIES`,
   php.ini).
3. **Base de données** : `php artisan migrate --force`, puis uniquement les données de référence :
   `php artisan db:seed --class="Database\Seeders\ReferenceDataSeeder" --force`.
   Relancer ensuite `php artisan security:check-config` : il vérifie aussi le schéma migré (aucune
   colonne `ON UPDATE CURRENT_TIMESTAMP` hors `updated_at`) et l'absence de **données de
   démonstration** (comptes `<rôle>@example.com` vérifiés, règles administratives fictives) : une
   base de démonstration ne doit jamais être copiée en production.
   Publier les documents légaux s'il n'y en a encore aucun (`php artisan legal:publish
   <fichier.json>` pour les CGU, puis pour la politique de confidentialité).
   Les brouillons de règles administratives s'importent volontairement, jamais par un seeder :
   `php artisan compliance:import-rules database/data/compliance/fr-2027.draft.json`.
   Ne jamais lancer `db:seed` sans `--class` en production, ni définir `SEED_DEMO_DATA`.
4. **Tâche cron** du planificateur en place (`php artisan schedule:run` chaque minute) : sans elle,
   aucun email (vérification, réinitialisation) ne part.
5. **Proxy et HTTPS** : si l'API passe derrière Cloudflare ou un autre proxy, `TRUSTED_PROXIES`
   contient ses plages d'adresses (sinon l'IP des clients et le HTTPS sont mal détectés). Le site
   n'est accessible qu'en HTTPS (redirection HTTP vers HTTPS au niveau de l'hébergement).
6. **PHP** : `upload_max_filesize` et `post_max_size` d'au moins 8 Mo, GD avec WebP, extensions
   `exif`, `fileinfo`, `dom` et `mbstring` (PDF des devis), `storage/framework/cache` accessible en
   écriture (cache des polices du PDF), `zend.exception_ignore_args = On`, `expose_php = Off`.
7. **Fichiers** : `backend/.env` non accessible depuis le web (seul `backend/public` est exposé),
   `APP_KEY` propre à la production et sauvegardée hors du serveur (voir « `APP_KEY` : sauvegarde
   et rotation » : sans elle, les données chiffrées sont perdues).
8. **IA** : laisser `AI_DRIVER=disabled` sur l'hébergement mutualisé. Ailleurs, après les étapes
   de `docs/v1/workspaces-rules.md` (section 7) : `security:check-config` (clé, endpoint de l'UE,
   plafonds, `max_execution_time`), puis `php artisan ai:test-connection`.
9. **Contrôle rapide après déploiement** : `GET /up` répond 200, une réponse de l'API porte bien
   `Strict-Transport-Security` et `Content-Security-Policy`, et `/` ne renvoie pas de page HTML.

## Chantiers de sécurité à venir

- Double authentification de l'équipe : obligatoire depuis le lot 1 pour les fonctions
  d'équipe ; reste à prévoir avec le back-office la création des comptes d'équipe avec 2FA
  imposée dès la première connexion.
- Étendre le journal d'audit (`admin_audit_logs`, en place pour la modération des événements, du
  Blog et des signalements, consultations comprises, et pour le statut premium) aux futures actions
  d'administration (back-office).
- Commentaires sur les articles (non prévus en v1 : charge de modération et spam).
- Suspension de compte, avec exposé des motifs (lot 8, décision 28).
- Notification « nouvelle connexion » et liste des sessions actives, avec déconnexion à distance.
- Alerte automatique (email ou outil de supervision) sur les avertissements du journal de sécurité
  (`projects.scraping_suspected`, `match_requests.limit_exceeded`, rafales de `auth.login_failed`).
- Détection des coordonnées (email, téléphone, IBAN) partagées dans les messages, pour avertir
  l'utilisateur avant l'envoi.
