# Loopartner V1 2027 — décisions validées

Décisions produit et techniques validées par l'équipe. Elles s'imposent aux sessions de
développement : ne pas les remettre en cause sans le demander. Une nouvelle décision s'ajoute en bas,
datée, avec sa justification ; une décision modifiée garde sa trace (date et raison du changement).

## Cadre (validé le 2026-11-16)

- Objectif : une **V1 2027 complète et commercialisable** (pas un MVP public), construite sur le
  backend Laravel existant, **sans refonte**. Les modules Accounts, Directory, Events, Blog et
  Compliance sont conservés.
- Monolithe modulaire (`backend/app/Modules/<Module>`), règle d'isolation des modules inchangée.
- **Base de données : MySQL/MariaDB uniquement (pas de PostgreSQL).** Toutes les migrations restent
  compatibles MySQL 8 et MariaDB 10.4 et plus (et SQLite pour les tests). Le lot 1 ajoute une
  intégration continue qui lance les tests sur un vrai serveur MariaDB, en plus de SQLite.
- Aucun taux, seuil ou montant réglementaire dans le code ; aucun module fictif ni parcours
  incomplet présenté comme terminé.
- Plateforme pour la France et l'Union européenne ; règles administratives automatisées françaises
  seulement pour la première publication ; architecture extensible à d'autres pays, langues et
  devises.

## Décisions (validées le 2026-11-16)

| # | Sujet | Décision |
|---|---|---|
| 1 | Travail Compliance non commité | Commité comme commit 7 après revue du diff (fait le 2026-11-16, `c19b068`). |
| 2 | Frontend | Next.js et TypeScript dans `frontend/`, **démarré une fois tous les lots backend terminés, Billing compris**. *Modifiée le 2026-11-16 (raison : terminer le backend avant de commencer l'interface). Ancienne version : « démarré après le lot 1, puis développé en parallèle des lots suivants ».* |
| 3 | Projets | Nouveau module **Workspaces** (pilotage : objectifs, étapes, tâches), **privé par défaut**, relié facultativement à une annonce Directory. Aucune donnée d'un workspace ne devient publique sans action explicite de l'utilisateur. |
| 4 | Paiement | **Remplacée le 2026-11-16** : **Stripe retenu**, voir « Décision 4 révisée » ci-dessous. |
| 5 | Offres | **Remplacée le 2026-11-16** : voir « Décision 5 révisée » ci-dessous. |
| 6 | Avis | Uniquement après une **interaction professionnelle vérifiable**, avec droit de réponse, signalement et modération. |
| 7 | Rendez-vous | Gestion **native** des créneaux, confirmations, annulations, rappels et fichiers `.ics`. |
| 8 | Analytics | **Agrégats côté serveur, sans cookie de suivi.** |
| 9 | Organisations | Architecture extensible ; **pas de module incubateur en V1**. |
| 10 | Hébergement | Préparer un VPS ou un hébergement Laravel géré **avant la bêta** ; pas de migration maintenant (Hostinger mutualisé en attendant). |
| 11 | Compliance | Règles françaises **2027 uniquement**, vérifiées par un professionnel ; **coffre-fort documentaire privé dans la V1**. |
| 12 | Langues et territoires | Français et anglais ; utilisateurs de l'UE acceptés ; **aucune règle administrative étrangère automatisée**. |

## Décision 5 révisée : offre Gratuit / Premium (validée le 2026-11-16)

**Ancienne décision 5** (validée le 2026-11-16 au matin, abandonnée le même jour) : Entrepreneur
**Free / Plus** ; Prestataire **Free / Pro / Business** ; prix, quotas et droits en données
configurables. **Raison du changement** : simplifier l'offre et attirer beaucoup d'utilisateurs
avec un prix bas, sur le modèle des SaaS grand public.

**Nouvelle décision 5**

- Offre simple, comme un SaaS grand public (Claude.ai, ChatGPT) : **deux niveaux seulement,
  Gratuit et Premium**. Un seul abonnement Premium, **au même prix pour les particuliers, les
  prestataires et les entrepreneurs** ; ses avantages s'adaptent au rôle de l'utilisateur.
- Paiement **mensuel ou annuel**.
- **Essai gratuit de 30 jours** (révisé le 2026-11-16, voir ci-dessous) : carte bancaire demandée
  dès le début, 0 € prélevé, Premium actif pendant l'essai, puis abonnement Premium mensuel ou
  annuel, **sauf annulation avant la fin de l'essai**. **Une seule période d'essai par personne.**
- Durée de l'essai, prix et avantages par rôle et quotas sont des **données configurables,
  jamais dans le code**. La grille pourra évoluer plus tard.
- Obligations : prix, date du premier prélèvement et reconduction affichés clairement **avant la
  souscription** ; **rappel avant la fin de l'essai** ; **résiliation en ligne simple**
  (obligation française de résiliation en quelques clics).
- Paiement : **Stripe** (décision 4 révisée).

*Versions précédentes de ce point (2026-11-16, lot 1)* : « Offre de lancement : les 2 premiers
mois à 2 €, puis le prix normal ; une seule offre de lancement par personne » ; « Paiement : un
marchand de référence (Paddle ou équivalent) devient l'option privilégiée face à Stripe ».
**Remplacées le 2026-11-16** (raison : modèle plus courant et plus transparent, sur l'exemple de Canva).
- Les formules Entrepreneur Free/Plus et Prestataire Free/Pro/Business sont **abandonnées**.

## Décision 4 révisée : Stripe (validée le 2026-11-16)

**Ancienne décision 4** (2026-11-16) : Stripe envisagé, choix final entre Stripe et un marchand de
référence (Paddle) au lot Billing ; marchand de référence privilégié par la décision 5 révisée.
**Raison du changement** : modèle plus courant et plus transparent, sur l'exemple de Canva.

**Nouvelle décision 4**

- **Stripe est retenu** : **Stripe Checkout hébergé** pour la saisie de la carte (authentification
  forte 3-D Secure gérée par Stripe), **portail client Stripe**, **factures Stripe**.
- **Aucune donnée de carte ne transite par nos serveurs** ; seuls des identifiants Stripe sont
  stockés.
- **Loopartner est le vendeur** : la TVA de chaque pays de l'UE (guichet unique OSS) et les
  factures sont à notre charge, **à valider avec l'expert-comptable** (y compris le calendrier de
  la facturation électronique et du e-reporting).
- **Paddle est abandonné.** Le paquet PHP est choisi et validé au plan du lot Billing, avant toute
  installation.

## Décisions du lot 1 (validées le 2026-11-16)

| # | Sujet | Décision |
|---|---|---|
| 13 | Intégration continue | **GitHub Actions**, déclenchée à **chaque push sur toutes les branches** (le projet n'utilise pas de pull request : l'utilisateur fusionne en local). Pint, PHPStan, `composer audit`, tests sur SQLite, puis **tests et migrations réelles** sur **MariaDB 10.4**, sur une **version récente de MariaDB** et sur **MySQL 8**. Les tests ne dépendent jamais d'un fichier `.env`. Raison : un nom d'index de 65 caractères (erreur 1059) n'a été vu que sur MariaDB. |
| 14 | CGU et confidentialité | Documents versionnés. Une **nouvelle version en vigueur bloque l'utilisation** (403, code `consent_required`) jusqu'à son acceptation, sauf pour lire les documents, accepter, se déconnecter et supprimer son compte. L'inscription exige l'acceptation des versions en vigueur ; chaque acceptation est datée et versionnée. Aucun compte réel n'existe : **pas de reprise des comptes existants**. Les comptes de démonstration acceptent automatiquement une **version de démonstration**. |
| 15 | Notifications | Brique du noyau : chaque email porte une **catégorie** ; l'utilisateur peut désactiver les catégories facultatives. Events, Blog, Directory et Compliance déclarent leurs catégories **sans changer leur comportement** (tous leurs tests existants restent verts sans modification, sauf ajout). |
| 16 | Droits par fonctionnalité | `GetEntitlements` (module Accounts) suit la décision 5 révisée : un seul plan Premium, des **avantages qui dépendent du rôle**, le tout **en configuration** (plans en base et paiement au lot Billing). Il s'appuie pour l'instant sur `IsPremium` et **reproduit exactement les avantages actuels** (quota du Blog, limites de Compliance). Blog et Compliance le consultent, sans changement de comportement. |
| 17 | 2FA de l'équipe | **Obligatoire pour les rôles `admin` et `redacteur`** : aucune fonction d'équipe sans 2FA confirmée et session ouverte avec un code, et l'équipe ne peut pas désactiver sa 2FA. Les comptes de démonstration de l'équipe (admin, rédacteur) fonctionnent en local avec un **secret 2FA documenté**. |

## Avantages Premium par rôle (validés le 2026-11-16)

| # | Sujet | Décision |
|---|---|---|
| 18 | Avantages Premium | La proposition du lot 1 est validée (tableau ci-dessous). **Aucune mise en avant payante dans les résultats** (prestataires, projets, événements, articles) : elle devrait être signalée comme publicité et fausserait la mise en relation. Les liens suivis du Blog ne sont jamais un avantage Premium. Toutes les valeurs sont dans `config/entitlements.php`. |

| Rôle | Avantage Premium | Lot |
|---|---|---|
| Prestataire, entrepreneur | Articles illimités (quota gratuit) ; suivi administratif : 5 entreprises, 3 délais de rappel, paliers personnalisés, parcours illimités, estimation | en place (lot 1) |
| Particulier | Plus de projets publiés en même temps ; plus de demandes de contact en attente et par jour | **lot Billing** (limites passées en configuration) |
| Particulier | Durée de publication d'un projet plus longue | lot Billing ou suivant, si validé au plan |
| Particulier | Comparaison et export PDF des devis reçus | lot Marketplace |
| Particulier, entrepreneur | Plusieurs workspaces, modèles de projet | lot Workspaces |
| Tous | Guides et modèles réservés | lot Avis, ressources et coffre-fort |
| Prestataire | Statistiques agrégées du profil | lot Back-office et analytics |
| Prestataire | Page de prise de rendez-vous, plus de créneaux | lot Rendez-vous |
| Prestataire | Devis PDF personnalisés (logo, mentions) | lot Marketplace |
| Prestataire | Plus de catégories sur le profil | en configuration, lot à fixer |
| Entrepreneur | Coffre-fort documentaire plus grand | lot Avis, ressources et coffre-fort |
| Entrepreneur | Plus d'événements publiés ou en attente | en configuration, lot à fixer |

Dans le lot Billing : `Project::MAX_PUBLISHED_PER_USER`, `MatchRequest::MAX_PENDING_SENT` et la
limite de demandes de mise en relation par jour passent en configuration (`GetEntitlements`),
pour donner aux particuliers leurs premiers avantages Premium.

## Plan du lot Billing et règle de travail (validés le 2026-11-16)

Le lot Billing est reporté à la fin du backend (lot 10, juste avant le durcissement) ; son plan
est gardé dans `docs/v1/ROADMAP.md`.

| # | Sujet | Décision |
|---|---|---|
| 19 | Plan Billing | **Paquet** : `stripe/stripe-php` seul, derrière une interface `StripeGateway` simulée dans les tests, installé seulement au lot Billing. **Premium payé** : alimente `premium_grants` par une Action publique d'Accounts (sources `trial` et `payment`). **Carte déjà utilisée pour un essai** : abonnement annulé juste après Checkout, rien n'est prélevé, email proposant de souscrire sans essai. **Avantages Premium des particuliers** : 25 projets publiés en même temps (10 en gratuit), 60 demandes en attente (30), 50 demandes par jour (20). **Délai de grâce** après un échec de paiement : 3 jours (configurable). **Stripe Tax** activé, prix TTC identique dans toute l'UE, à confirmer avec l'expert-comptable. |
| 20 | Règle de travail | Un plan est toujours présenté avant de coder un lot. Les **décisions purement techniques** (structure du code, noms, index, découpage des commits, limites de débit cohérentes avec l'existant) sont tranchées par l'assistant et notées dans le plan. Sont soumis à l'utilisateur uniquement : **l'argent et la facturation**, **le droit** (CGU, CGV, RGPD, DSA, consommation), **les durées de conservation des données personnelles**, **les nouveaux paquets**, et **ce que les utilisateurs voient ou subissent** (règles métier, limites, emails, refus). |

## Décisions du lot 2 : signalements et modération (validées le 2026-11-16)

Plan validé avec les recommandations A à K et les précisions de l'utilisateur. Analyse du
règlement (UE) 2022/2065 et sources : `docs/v1/moderation-rules.md`. Loopartner est traité comme
une petite entreprise (art. 15 §2, 19 et 29 : à faire valider par le juriste).

| # | Sujet | Décision |
|---|---|---|
| 21 | Recours interne | Appliqué **volontairement** (l'art. 20 ne s'impose pas à une petite entreprise) et aligné sur lui : **gratuit, en ligne, 6 mois** après la décision, examiné par un humain ; ouvert à l'auteur du contenu (contre une restriction) et au signalant (contre une absence de mesure). Un recours en attente à la fois, trois au plus par personne et par décision. |
| 22 | Catégories de signalement | Arnaque ou fraude ; contenu haineux ou harcèlement ; vie privée ou données personnelles ; contrefaçon ou propriété intellectuelle ; activité ou profession illégale ; abus sexuels sur mineurs ; incitation à la violence ou terrorisme ; pratique commerciale trompeuse ; contraire aux CGU ; autre. Libellés à faire valider par le juriste. |
| 23 | Profil prestataire masqué | Traité comme **non publié partout** (recherche, page publique, consultation des projets, événements et articles). L'exposé des motifs indique **ce que le prestataire doit corriger** quand c'est possible ; après correction, il demande le **réexamen par la voie du recours** (mesure proportionnée, art. 14 §4). |
| 24 | Événement masqué | Retiré de l'agenda, inscriptions, rappels et annonces fermés ; les inscrits d'un événement à venir reçoivent un **email neutre** et gardent leur inscription. L'annulation définitive reste l'outil existant d'Events. |
| 25 | Signalement sans compte | Ouvert à toute personne (art. 16) ; **pas de captcha** (nouveau service) : limites par IP, par compte et par email, champ piège, accusé de réception neutre. |
| 26 | Emails d'Events et du Blog | Leurs emails de décision restrictive gagnent une ligne vers **l'exposé des motifs et le recours** ; c'est le seul changement visible dans ces modules (tests existants inchangés). |
| 27 | Durées de conservation | Identité du signalant, explication et copie du contenu : **12 mois** après la clôture du signalement. Exposés des motifs, recours et signalements traités : **24 mois** après la fin du délai de recours ou la levée. |
| 28 | Suspension de compte | **Reportée au lot 8** (back-office) : l'art. 23 ne s'impose pas à une petite entreprise ; le lot 2 agit contenu par contenu, plus le blocage existant. Toute suspension future aura son exposé des motifs (art. 17 §1 d). |
| 29 | Identité du signalant | **Jamais révélée** à l'auteur du contenu en V1 ; l'admin voit « compte » ou « sans compte » et le nom donné sans compte, jamais l'email. |
| 30 | Points de contact | `contact@loopartner.com` (utilisateurs, art. 12) et `legal@loopartner.com` (autorités, art. 11), en **français et anglais**, en variables d'environnement (`MODERATION_CONTACT_EMAIL`, `MODERATION_AUTHORITIES_EMAIL`), vérifiés par `security:check-config` en production. |
| 31 | Signalements graves | « Abus sexuels sur mineurs » et « incitation à la violence ou terrorisme » : **alerte email immédiate** aux admins avec 2FA, **sans aucun contenu**, et tête de file. Objectifs de traitement en configuration : **24 heures** pour ces catégories, **7 jours** pour les autres ; la file affiche les retards. Procédure art. 18 (PHAROS) documentée dans `moderation-rules.md`, à faire valider par le juriste ; **aucun envoi automatique** aux autorités. Pour les abus sexuels sur mineurs, la copie conservée reste **textuelle** et aucune image n'est copiée ni réaffichée dans l'administration. |

Décisions techniques du plan (décision 20) : module `Moderation` et contrat du noyau
`ModerationTarget` (un adaptateur par type de contenu, déclaré dans `config/moderation.php`) ;
restriction réversible par colonne (`moderation_restricted_at`) sans toucher au statut du contenu ;
reprise de `abuse_reports` dans `moderation_notices` par une migration ; décisions d'Events et du
Blog transmises par l'événement du noyau `ContentModerated` ; modération réservée aux admins
(les rédacteurs gardent leurs flux du Blog).

## Décisions antérieures toujours en vigueur (rappel)

- Compliance, quatre yeux : `compliance.require_distinct_verifier` à **false par défaut** tant que
  l'équipe ne compte qu'un admin ; la vérification réelle est faite par un expert-comptable externe
  sans compte admin ; `verification_note` obligatoire (au moins 30 caractères : qui, quand, sur
  quelle source). Validé le 2026-11-13.
- Compliance, brouillons de règles : une valeur n'est remplie que si elle a été lue sur une page
  officielle pendant le travail (« lue sur [URL] le [date] ») ; sinon `null` avec « à compléter ».
  Jamais de valeur de mémoire. Validé le 2026-11-13.
- Compliance, répartition gratuit / premium, chiffrement, durées de conservation, abonnement `.ics`
  gratuit, suppression d'une entreprise avec confirmation récente du mot de passe : plan validé le
  2026-11-13 (voir CLAUDE.md, « Module Compliance »).
