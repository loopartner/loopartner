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
| 2 | Frontend | Next.js et TypeScript dans `frontend/`, **démarré le 2026-11-20 à partir des maquettes Figma, en parallèle des lots 6 à 11** (décision 71). *Modifiée le 2026-11-20 (raison : décision de l'utilisateur de construire et brancher l'interface sans attendre). Version du 2026-11-16 : « démarré une fois tous les lots backend terminés, Billing compris » ; version initiale : « démarré après le lot 1, puis développé en parallèle des lots suivants ».* |
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

*Tableau remplacé le 2026-11-16 par la grille de la décision 35 (raison : nouvelles fonctionnalités
Premium de la décision 33) ; conservé pour la trace.*

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

## Prix et fonctionnalités Premium de la V1 (validées le 2026-11-16)

| # | Sujet | Décision |
|---|---|---|
| 32 | Prix du Premium | Détail ci-dessous. **Complète** la décision 5 révisée (qui laissait les prix « configurables » sans valeur) ; la décision 19 reste en vigueur (montants lus sur les prix Stripe, jamais dans le code). |
| 33 | Fonctionnalités Premium à forte valeur | Copilote de projet, comparateur de devis, accès anticipé aux projets, dossier entreprise partageable et badge « Entreprise immatriculée vérifiée », radar d'opportunités, statistiques des prestataires : **ajoutés à la V1**, détail ci-dessous. |
| 34 | Brique d'IA du noyau | Créée au lot Workspaces, détail ci-dessous. |
| 35 | Grille des avantages par rôle | Tableau ci-dessous. **Remplace le tableau de la décision 18** (conservé plus haut pour la trace) ; les principes de la décision 18 restent en vigueur (aucune mise en avant payante dans les résultats, liens suivis du Blog jamais Premium). |

### Décision 32 : prix du Premium

- **Prix normal** : **14,90 € TTC par mois** ou **149 € TTC par an**, même prix pour les
  particuliers, les prestataires et les entrepreneurs.
- **Essai gratuit de 30 jours** avec carte bancaire : inchangé (décision 5 révisée).
- **Prix fondateur** : les **500 premiers abonnés** paient **9,90 € TTC par mois** ou **99 € TTC
  par an** (même rapport que le prix normal : environ deux mois offerts ; validé le 2026-11-16),
  **à vie, tant que l'abonnement n'est pas résilié**. Après une résiliation, un nouvel abonnement
  se fait au prix normal en vigueur.
- **Parrainage** : **un mois offert au parrain et au filleul**.
- **Codes partenaires** : **trois mois offerts**.
- **Toutes ces valeurs sont configurables** (prix Stripe, nombre de places au prix fondateur,
  durées offertes), jamais dans le code.
- **Fixé au plan du lot Billing** : mécanisme Stripe (prix dédiés, coupons, codes promotionnels,
  crédits sur le solde du client) ; conditions (ce qui compte comme « abonné » pour les 500 places,
  cumul avec l'essai, cumul du parrainage et d'un code partenaire, plafond de parrainages,
  prévention de l'auto-parrainage) ; **information des abonnés avant toute hausse de prix** ;
  textes dans `docs/v1/billing-rules.md` et les CGV.
- **Les prix exacts restent à confirmer avec l'expert-comptable** (TVA incluse, guichet unique
  OSS) ; conditions du prix fondateur, du parrainage et des codes partenaires à faire valider par
  le juriste.

### Décision 33 : fonctionnalités Premium à forte valeur (ajoutées à la V1)

- **Copilote de projet** (lot 5, Workspaces ; brique d'IA de la décision 34) : à partir de la
  description d'un projet, une IA propose une **feuille de route**, les **catégories de
  prestataires utiles** (catégories actives de l'annuaire, reliées à `GET /providers`) et un
  **cahier des charges**. Il ne donne **jamais d'avis juridique ou fiscal** et chaque réponse
  porte un **avertissement systématique** (comme le `disclaimer` de Compliance). Gratuit : **une
  feuille de route** ; Premium : usage étendu, avec quotas en configuration.
- **Comparateur de devis** (lot 3, Marketplace) : devis reçus **côte à côte**, **éléments
  manquants**, **questions à poser** au prestataire. Étend l'avantage « comparaison et export PDF
  des devis reçus » de la décision 18, pour les particuliers et les entrepreneurs. **Au lot 3, il
  fonctionne avec des règles explicables** (lignes, quantités, validité, mentions absentes), sans
  IA. La brique d'IA du lot 5 (décision 34) **pourra l'enrichir ensuite**, de façon
  **facultative** et dans les **quotas de la décision 34** ; le comparateur reste complet sans
  elle (IA désactivée ou quota atteint).
- **Accès anticipé de 24 heures** aux nouveaux projets pour les prestataires Premium (lot 3,
  Marketplace) : un projet publié n'est visible que des prestataires Premium pendant 24 heures
  (durée configurable), puis de tous. Ce n'est **pas un classement** : l'ordre des résultats reste
  le même pour tous (décision 18 inchangée). **Annoncé de façon transparente dans les CGU**
  (traitement différencié), à faire valider par le juriste, y compris l'information du porteur de
  projet.
- **Dossier entreprise partageable et badge « Entreprise immatriculée vérifiée »** (lot 6, Avis,
  ressources et coffre-fort) : documents rangés dans le coffre-fort (décision 11), **lien de
  partage sécurisé et temporaire** (révocable), **rappels d'expiration** des documents ;
  vérification de l'entreprise par l'**API publique de recherche d'entreprises de l'État**. Le
  badge s'intitule **« Entreprise immatriculée vérifiée »** : il atteste **seulement
  l'immatriculation** contrôlée par cette API, **jamais la qualité du travail**. Libellé et
  explication affichée à faire valider par le juriste. *Libellé précédent (2026-11-16) :
  « entreprise vérifiée » ; raison du changement : dire exactement ce qui est vérifié.*
- **Radar d'opportunités** (lot 7, Recommandations) : aides, concours, appels à projets et
  événements **personnalisés**, avec leurs **dates limites dans le calendrier**, pour les
  **prestataires, les entrepreneurs et les particuliers** (porteurs de projets : aides à la
  création, concours, événements pour se lancer). Gratuit : **un email par mois** ; Premium :
  **chaque semaine et complet**, pour les trois rôles. *Particuliers ajoutés le 2026-11-16
  (version précédente : prestataires et entrepreneurs seulement).* Source des aides, concours et appels
  à projets à choisir au plan du lot (nouveau service : décision de l'utilisateur).
- **Statistiques des prestataires** (lot 8, Back-office et analytics) : agrégats côté serveur,
  sans cookie de suivi (décision 8).

### Décision 34 : brique d'IA du noyau

Créée au **lot 5 (Workspaces)**, utilisée d'abord par le copilote de projet, puis par tout module
qui en a besoin (le noyau ne dépend d'aucun module).

- Appels au service d'IA **derrière une interface**, **simulée dans les tests** : aucun appel
  réseau réel dans les tests (comme `StripeGateway`).
- **Quotas par utilisateur et par plan en configuration** (`config/entitlements.php`, jamais dans
  le code).
- **Suivi des coûts** (consommation et coût estimé de chaque appel, agrégés).
- **Journalisation sans le contenu des échanges** : ni la question, ni la réponse, ni la
  description du projet.
- **Possibilité de désactiver l'IA** en configuration : la fonctionnalité devient indisponible,
  sans erreur.
- **Information dans la politique de confidentialité**, à faire valider par le juriste (y compris
  le sous-traitant, le lieu de traitement et la durée de conservation des échanges).
- La réponse de l'IA est traitée comme une **donnée non fiable** : texte brut, catégories filtrées
  sur la liste des catégories actives.
- **Le choix du fournisseur et du modèle sera présenté dans le plan du lot 5** (nouveau service ou
  paquet : décision de l'utilisateur).

### Décision 35 : grille des avantages Premium par rôle

Chaque lot ajoute ses valeurs dans `config/entitlements.php` (rien n'y est modifié avant). Les
valeurs gratuites citées sont les limites actuelles du code ou des décisions validées ; « à fixer »
se tranche au plan du lot. Les propositions déduites des décisions 18 et 33 (un workspace gratuit,
devis PDF standard, coffre-fort sans lien de partage ni badge, comparateur de devis pour les
entrepreneurs) sont **validées le 2026-11-16**.

| Rôle | Fonctionnalité Premium | Version gratuite | Lot |
|---|---|---|---|
| Particulier | 25 projets publiés en même temps | 10 | lot 10 (Billing, décision 19) |
| Particulier | 60 demandes en attente, 50 demandes par jour | 30 en attente, 20 par jour | lot 10 (Billing, décision 19) |
| Particulier | Durée de publication d'un projet plus longue | 60 jours | lot 10 ou suivant, si validé au plan |
| Particulier | Comparateur de devis (côte à côte, éléments manquants, questions à poser) et export PDF | lecture de chaque devis reçu | lot 3 (Marketplace) |
| Particulier | Copilote de projet : usage étendu, quotas en configuration | une feuille de route | lot 5 (Workspaces) |
| Particulier | Plusieurs workspaces, modèles de projet | un workspace | lot 5 (Workspaces) |
| Particulier | Guides et modèles réservés | ressources publiques | lot 6 |
| Particulier | Radar d'opportunités chaque semaine et complet (aides à la création, concours, événements pour se lancer) | un email par mois | lot 7 (Recommandations) |
| Prestataire | Articles illimités | 2 articles soumis | en place (lot 1) |
| Prestataire | Suivi administratif : 5 entreprises, 3 délais de rappel, paliers personnalisés, parcours illimités, estimation | 1 entreprise, 1 délai (3, 7 ou 14 jours), paliers 80 % et 100 %, 1 parcours actif, sans estimation | en place (lot 1) |
| Prestataire | Accès anticipé de 24 heures aux nouveaux projets | projets visibles 24 heures après leur publication | lot 3 (Marketplace) |
| Prestataire | Devis PDF personnalisés (logo, mentions) | devis PDF standard | lot 3 (Marketplace) |
| Prestataire | Page de prise de rendez-vous, plus de créneaux | à fixer | lot 4 (Rendez-vous) |
| Prestataire | Dossier entreprise partageable et badge « Entreprise immatriculée vérifiée » | coffre-fort privé, sans lien de partage ni badge | lot 6 |
| Prestataire | Guides et modèles réservés | ressources publiques | lot 6 |
| Prestataire | Radar d'opportunités chaque semaine et complet | un email par mois | lot 7 (Recommandations) |
| Prestataire | Statistiques du profil | à fixer | lot 8 (Back-office et analytics) |
| Prestataire | Plus de catégories sur le profil | 5 | en configuration, lot à fixer |
| Entrepreneur | Articles illimités | 2 articles soumis | en place (lot 1) |
| Entrepreneur | Suivi administratif : 5 entreprises, 3 délais de rappel, paliers personnalisés, parcours illimités, estimation | 1 entreprise, 1 délai (3, 7 ou 14 jours), paliers 80 % et 100 %, 1 parcours actif, sans estimation | en place (lot 1) |
| Entrepreneur | Comparateur de devis et export PDF | lecture de chaque devis reçu | lot 3 (Marketplace) |
| Entrepreneur | Copilote de projet : usage étendu, quotas en configuration | une feuille de route | lot 5 (Workspaces) |
| Entrepreneur | Plusieurs workspaces, modèles de projet | un workspace | lot 5 (Workspaces) |
| Entrepreneur | Dossier entreprise partageable et badge « Entreprise immatriculée vérifiée » ; coffre-fort plus grand | coffre-fort privé de taille gratuite, sans lien de partage ni badge | lot 6 |
| Entrepreneur | Guides et modèles réservés | ressources publiques | lot 6 |
| Entrepreneur | Radar d'opportunités chaque semaine et complet | un email par mois | lot 7 (Recommandations) |
| Entrepreneur | Plus d'événements publiés ou en attente | 3 en attente, 20 publiés à venir | en configuration, lot à fixer |

## Décisions du lot 3 : Marketplace (validées le 2026-11-17)

Plan validé avec les recommandations 1 à 15 et les précisions de l'utilisateur. Analyse des
textes (devis, place de marché, DSA art. 29 à 32, TVA, acceptation en ligne) et points à faire
valider : `docs/v1/marketplace-rules.md`.

| # | Sujet | Décision |
|---|---|---|
| 36 | Moment du devis | Un devis ne s'envoie **qu'après l'acceptation de la demande de mise en relation** : le nom du client et le lieu d'exécution sont des mentions obligatoires du devis (arrêté du 24 janvier 2017, art. 4), et le nom du porteur de projet n'est révélé au prestataire qu'à l'acceptation. Le porteur accepte plusieurs propositions, reçoit leurs devis et les compare. |
| 37 | Traçabilité des professionnels | Seuls des **professionnels déclarés** publient des offres et envoient des devis : identité professionnelle (dénomination, forme juridique, SIREN, registre, adresse, coordonnées, assurance, médiateur) et **autocertification** datée et versionnée. **Pas d'art. 30 DSA complet** (ni pièce d'identité, ni coordonnées bancaires) : l'art. 29 en dispense une petite entreprise (à faire valider par le juriste, statut consolidé compris). Le SIREN est **contrôlé dans sa forme** (9 chiffres, clé de Luhn) ; la vérification par l'API publique de l'État et le badge « Entreprise immatriculée vérifiée » restent au lot 6, mais leurs colonnes sont créées dès le lot 3. |
| 38 | Affichage de la qualité | Sur l'offre : « Professionnel (statut déclaré) », dénomination et SIREN (Code de la consommation, D111-8 II) ; l'identité complète n'apparaît que sur le devis. À faire valider par le juriste. |
| 39 | Publication des offres | Sans validation préalable, comme les profils, mais **signalables et masquables** (adaptateur `offer`). 20 offres publiées et 30 offres au total par prestataire ; pas d'image en V1. |
| 40 | Prix des offres | Prix **indicatif saisi TTC** par le prestataire, avec la mention « prix indicatif, seul le devis engage » ; aucun calcul de TVA sur les offres. Affichage TTC envers les consommateurs à faire valider par le juriste. |
| 41 | Acceptation en ligne | Récapitulatif puis confirmation (« double clic », C. civ. 1127-2), case d'acceptation, **demande expresse d'exécution immédiate** facultative pour un consommateur (L221-25), preuve (date, IP, empreinte du contenu). Pas de signature électronique qualifiée. Mention « Loopartner n'est pas partie au contrat ». À faire valider par le juriste. |
| 42 | Rétractation en ligne | Fonction de rétractation en ligne (L221-21, en vigueur le 19 juin 2026) fournie par Loopartner pour les devis acceptés par un consommateur, au nom du prestataire, pendant `marketplace.withdrawal_days` (14). À faire valider par le juriste (titulaire de l'obligation, décret d'application). |
| 43 | Lecture de la décision 35 | Le **PDF de chaque devis est gratuit pour les deux parties** (support durable). Premium du client : comparateur et **export PDF de la comparaison** ; Premium du prestataire : logo et mentions personnalisées sur ses devis. |
| 44 | Accès anticipé | Fenêtre de 24 heures (configurable) **ouverte à chaque publication ou republication** ; le porteur voit sa date de fin dans son projet ; ordre des résultats identique pour tous. Texte des CGU (P2B art. 3 et 5 §3) à faire valider par le juriste. |
| 45 | Signalement des devis | Le client peut signaler un devis reçu (contenu privé, comme les messages : adaptateur `quote`, non signalable par le formulaire public). |
| 46 | Emails | « Devis reçu » et « devis refusé » : catégorie facultative `marketplace.quotes`. « Devis accepté » (confirmation au client et au prestataire) et rétractation : catégorie obligatoire `marketplace.quote_decisions`. **La confirmation d'acceptation contient le PDF du devis accepté en pièce jointe**, généré au moment de l'envoi, pour que chaque partie garde le contrat sur un support durable même sans accès à son compte ; le corps de l'email reste sans montant ni ligne. À noter pour le juriste. |
| 47 | Durées de conservation | Brouillons : 12 mois sans modification. Devis refusés, retirés, expirés ou remplacés : 12 mois après ce changement. Devis acceptés et preuve d'acceptation : tant que la conversation existe, donc 12 mois après la suppression d'un des deux comptes (règle actuelle des conversations). Les parties gardent leur PDF. |
| 48 | Paquet PDF | `dompdf/dompdf` ^3.1, version exacte fixée dans `composer.lock`, installé seulement au commit des PDF, `composer security` avant et après l'installation ; ressources distantes, PHP et JavaScript désactivés. |
| 49 | TVA | Nouveau type de règle Compliance `vat_rate` (taux, libellé, champ d'application, mention, certification du client, caractère exclusif) ; brouillons des taux 2027 **sans valeur** tant qu'elle n'est pas lue pour 2027 ; arrondi au centime par taux, à faire valider. **V1 limitée aux prestataires établis en France, en euros**. Aucun devis ne peut être envoyé tant qu'aucun taux n'est vérifié par l'expert-comptable. |
| 50 | Règles et limites visibles | Validité d'un devis de 1 à 180 jours (30 par défaut) ; 100 lignes ; 20 brouillons par prestataire ; un seul devis envoyé à la fois par demande (une nouvelle version remplace la précédente) ; 10 devis comparés au plus ; règles du comparateur et seuils (forfait unique au-delà de 80 % du total, écart de 30 % à la médiane à partir de 3 devis, validité de moins de 7 jours) en configuration ; avertissement « comparaison automatique fondée sur des règles, ni avis sur la qualité ni conseil ». |

Décisions techniques du plan (décision 20) : nouveau module `Marketplace` (identité
professionnelle, offres, devis, comparateur, PDF) ; Actions publiques de lecture dans Directory
(`GetMatchRequestContext`, `FindOwnProject`), Compliance (`GetVatRates`) et Accounts
(`PublishedProviderConstraint`) ; clé étrangère en cascade du devis vers sa demande de mise en
relation ; montants en centimes, quantités en millièmes, taux en points de base, aucun décimal ;
contenu figé à l'envoi avec une empreinte SHA-256 contrôlée à l'acceptation ; identités chiffrées
en base ; accès anticipé par un scope de Directory (`config/directory.php`).

## Correction du lot 3 : logo figé des devis (validée le 2026-11-17)

| # | Sujet | Décision |
|---|---|---|
| 51 | Logo Premium des devis | Le logo est **figé à l'envoi** de chaque devis et de chaque nouvelle version, comme les mentions personnalisées : copie PNG réduite, encodée en base64 dans la ligne du devis (`marketplace_quotes.issuer_logo`), **256 Ko au plus** une fois encodée (côtés de 400, 300, 200 puis 128 px essayés dans l'ordre ; au-delà, le devis part **sans logo** plutôt que d'échouer). Son empreinte SHA-256 entre dans le contenu accepté (`content_hash`). Le PDF d'un devis envoyé ou accepté n'utilise que cette copie : changer ou supprimer le logo du profil, ou perdre le Premium, ne change plus un devis envoyé. La copie suit la conservation du devis (purge, conversation, suppression de compte), n'a ni URL ni fichier, et n'est jamais renvoyée par l'API. **Les devis envoyés avant la correction sortent sans logo** ; pas de commande de reprise. Raison : un devis accepté est un contrat, son PDF doit rester celui que le client a accepté. |

Décisions techniques : copie en base plutôt que fichier sur un disque privé (elle disparaît avec
la ligne, sans code de nettoyage, et n'est servable par aucune URL) ; limite de taille choisie
sous `max_allowed_packet` (souvent 1 Mo sur XAMPP, parfois limité chez l'hébergeur).

## Décisions du lot 4 : rendez-vous et agenda (validées le 2026-11-17)

Plan validé avec les recommandations 1 à 7 et les précisions de l'utilisateur. Sources
officielles consultées, textes et points à faire valider : `docs/v1/appointments-rules.md`.

| # | Sujet | Décision |
|---|---|---|
| 52 | Qui réserve chez qui | Seuls les **prestataires** (email vérifié, profil publié avec une catégorie) publient des disponibilités. **Voie 1 (tous les prestataires, gratuite)** : dans une **demande de mise en relation acceptée**, le porteur de projet réserve un créneau (confirmé tout de suite si le type est en confirmation automatique, sinon à confirmer par le prestataire) ou le prestataire propose un créneau que le client confirme. **Voie 2 (Premium du prestataire)** : **page de réservation publique** sur son profil ; tout particulier ou entrepreneur avec un email vérifié réserve un type public en écrivant un motif, ce qui envoie en même temps une **demande de contact Directory** (mêmes limites et blocages) ; la **confirmation du prestataire est obligatoire** : elle accepte la demande (conversation ouverte, nom du client révélé), le refus la refuse, l'expiration la fait expirer, l'annulation par le client la retire. Déjà en relation acceptée : 409 (réserver depuis la relation) ; demande en attente : 409. Blocage : nouvelles réservations et reports refusés (403), les rendez-vous existants restent annulables. Pas de lien automatique avec les devis en V1 (même demande de mise en relation). |
| 53 | Ce que chaque partie voit | Visiteur de la page publique : types publics (titre, description, durée, mode de lieu) et heures de début libres, sans distinguer un créneau réservé d'un créneau fermé. Client : résumé public du prestataire, type, dates ; le lieu complet (adresse du prestataire, lien de visio) seulement une fois confirmé. Prestataire : avant confirmation d'une réservation de la page publique, le type de compte, le pays et le motif du client, **jamais son nom** ; après, son nom, sa note et l'adresse ou le téléphone qu'il a donnés si le mode l'exige. Jamais d'email, de `user_id` ni de téléphone personnel du prestataire ; compte supprimé : `{"deleted": true}` ; admins : 404. |
| 54 | Quotas (prestataire, `config/entitlements.php`) | Gratuit / Premium : page publique non / oui ; types réservables 2 / 10 ; plages hebdomadaires 10 / 40 ; exceptions à venir 20 / 100 ; horizon de réservation 30 / 90 jours ; rendez-vous à venir (confirmés et en attente) 30 / 200 ; rappels : un délai (24 h) / jusqu'à deux parmi 2 h, 24 h et 48 h. **Clients** (tous rôles) : 5 demandes en attente, 20 rendez-vous à venir, 3 rendez-vous à venir par relation. **Prestataire** (précision du 2026-11-17) : au plus **3 propositions de créneau en attente par relation** et **30 propositions en attente au total** (valeur alignée sur la limite quotidienne `appointment-bookings`). **Fin du Premium** : rien n'est supprimé, les rendez-vous confirmés sont honorés ; la page publique répond 404, seuls les premiers types (dans l'ordre choisi) restent réservables, les nouvelles réservations suivent l'horizon et les rappels gratuits. |
| 55 | Annulation et report | Annulation par chaque partie **jusqu'au début**, sans frais ni pénalité, motif en liste fermée (`schedule_conflict`, `no_longer_needed`, `other`) et message facultatif (signalable). **Report** : chaque partie propose un nouveau créneau, retenu jusqu'à la réponse de l'autre ; accepté, le rendez-vous est déplacé (même uuid, version + 1) ; refusé, l'original reste ; le client qui se reporte sur un type en confirmation automatique est déplacé directement ; un report en attente à la fois, 3 reports au plus. Demande en attente : expire **48 heures** après la demande, ou plus tôt au délai de prévenance avant le début. Aucun compteur d'annulations ni marquage « non honoré ». |
| 56 | Emails | Catégorie **obligatoire** `appointments.bookings` (demande, proposition, confirmation, refus, annulation, report, expiration, pour les deux parties) et catégorie **facultative** `appointments.reminders` (rappels, aux deux parties). Contenu : titre du type et date dans le fuseau du destinataire ; jamais de note, d'adresse, de téléphone, de lien de visio ni de nom. **Un `.ics` (titre et dates seulement, sans lieu ni lien) est joint à l'email de confirmation** (et à celui d'un report accepté). L'abonnement au calendrier de Compliance est **conservé** à côté de l'abonnement unique. |
| 57 | Durées de conservation | Lieu (adresse, téléphone, lien de visio) et notes libres : effacés **3 mois** après la date du rendez-vous ou sa clôture. Rendez-vous refusés, annulés ou expirés : supprimés **12 mois** après ce changement. Rendez-vous passés (dates, type, statut) : supprimés **24 mois** après leur date, ou avant avec leur conversation (clé étrangère en cascade). Traces des rappels et exceptions passées : **3 mois**. Réglages, types, plages et abonnement à l'agenda : tant que le compte existe. À reporter dans la politique de confidentialité. |
| 58 | Note libre | Facultative, **500 caractères** au plus (motif d'une réservation de la page publique : 20 à 500), texte brut, avec un avertissement près du champ : « n'indiquez pas d'information de santé ni d'autre donnée sensible » (texte à faire valider par le juriste ; recommandation de la CNIL d'encadrer les zones de commentaires libres). |
| 59 | Export RGPD général | Placé au **lot 11** (durcissement avant la bêta) : contrat du noyau `PersonalDataSource` (un adaptateur par module), `GET /me/export` en libre-service, JSON structuré ; l'export Compliance en devient une section. Le lot 4 écrit dès maintenant la classe interne `Appointments\Support\AppointmentsPersonalData` (**non exposée, testée**) et l'inventaire de ses données dans SECURITY.md. |

Décisions techniques du plan (décision 20) : nouveau module `Appointments` ; brique du noyau
`App\Support\Agenda` (contrat `AgendaSource`, un adaptateur par module déclaré dans
`config/agenda.php` : Appointments, Events, Compliance) pour l'agenda unifié et l'abonnement
unique (`agenda_feeds`) ; brique du noyau `App\Support\Time\LocalTime` (heure locale et UTC,
trou de l'heure d'été avancé à l'instant du changement, heure ambiguë : première occurrence pour
un début, dernière pour une fin) ; jeton d'abonnement extrait dans `App\Support\Calendar\FeedToken`
(Compliance lui délègue, comportement inchangé) ; Action publique Directory
`ManageAppointmentContact` (demande de contact de la page publique, acceptée, refusée, retirée ou
expirée sans email en double) ; **double réservation impossible** : verrou de la ligne du
calendrier du prestataire pendant la vérification et l'insertion, plus un index unique
`slot_key` (calendrier et début UTC) sur les créneaux retenus, traduit en 409
`slot_unavailable` ; créneaux découpés en temps réel à l'intérieur des plages locales ; rappels
« 24 h » en durée réelle ; lieu et notes chiffrés en base ; clé étrangère en cascade du
rendez-vous vers sa demande de mise en relation.

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

## Décisions du lot 5 : workspaces, onboarding et brique d'IA (validées le 2026-11-18)

Plan validé avec les recommandations D1 à D12 et les précisions de l'utilisateur. Sources
officielles consultées, contenu envoyé à l'IA, textes et points à faire valider :
`docs/v1/workspaces-rules.md`.

| # | Sujet | Décision |
|---|---|---|
| 60 | Fournisseur d'IA | **Mistral AI, modèle Mistral Small 4, endpoint régional de l'UE** (`https://api.eu.mistral.ai`, inférence garantie dans l'UE, prix ×1,1) : société française, pas de transfert vers les États-Unis, contrat de sous-traitance incorporé aux conditions. Pilote écrit avec le client `Http` de Laravel, **aucun paquet** ; le pilote refuse tout autre hôte que l'endpoint de l'UE. `AI_DRIVER=disabled` par défaut (production comprise) tant que l'exploitant n'a pas créé le compte, désactivé l'opt-out d'entraînement, demandé la conservation nulle (ZDR) et renseigné la clé (étapes dans `workspaces-rules.md`). Tests avec de fausses réponses seulement. Comparaison avec Anthropic et OpenAI : `workspaces-rules.md`. |
| 61 | Quotas du copilote | Particuliers et entrepreneurs. **Gratuit** : une feuille de route et une régénération **à vie** (deux générations ; un échec ne compte pas), pas de cahier des charges. **Premium** : **30 générations par mois et 10 par jour** (feuilles de route et cahiers des charges confondus). **Email vérifié obligatoire pour toute génération** (403 `email_not_verified`) ; le workspace reste utilisable sans vérification. Gratuit épuisé : 403 `premium_required` ; quota Premium atteint : 429 `ai_quota_exceeded` avec `resets_at`. |
| 62 | Plafonds de coût | **0,50 $ par utilisateur et par mois**, **50 $ par mois et 5 $ par jour au global** (en configuration, en cents). Coût réservé sous verrou avant chaque appel, ajusté au coût réel après. Plafond global atteint : IA indisponible (503 `ai_unavailable`, `reason: capacity`) ; plafond utilisateur : 429 `ai_quota_exceeded`. Alerte email aux admins avec 2FA à **80 % et 100 %** du budget mensuel global (catégorie obligatoire `ai.budget_alerts`, une fois par palier et par mois). |
| 63 | Workspaces | Module privé pour les **particuliers et les entrepreneurs** (403 pour les autres rôles ; email vérifié non requis). **Gratuit : 1 workspace non archivé ; Premium : 10** ; 50 au total. **Modèles de projet réservés au Premium.** Limites : 20 objectifs, 30 étapes, 50 tâches par étape et 300 par workspace ; titre 160 caractères, description 5 000, note 2 000. **Fin du Premium** : rien n'est supprimé ; le workspace choisi (`activate`) reste modifiable, les autres passent en lecture seule (lecture, archivage, suppression). |
| 64 | Contenu envoyé à l'IA | **Liste blanche exacte** (`workspaces-rules.md`) : titre et description du workspace **expurgés** (emails, téléphones, IBAN, URL, numéros SIREN/SIRET remplacés), pays, budget, date de début souhaitée, travail à distance, langue, type de compte, titres des étapes existantes, catégories actives (publiques). **Jamais** : nom, email, identifiant, ville, notes, données d'un autre utilisateur. Prévisualisation du contenu exact avant l'envoi ; l'information sur l'IA (version) doit être lue avant la première génération. |
| 65 | Durées de conservation | Workspaces et leur contenu : tant que le compte existe. Suggestion du copilote (réponse de l'IA) : **90 jours** après sa génération. Journal des appels à l'IA (`ai_usages` : fonctionnalité, modèle, jetons, coût, sans contenu) : **13 mois** ; ensuite seuls des totaux par jour sans utilisateur restent. Décompte des générations gratuites : tant que le compte existe. Chez Mistral : 30 jours sans ZDR, aucun avec ZDR. À faire valider par le juriste (J8). |
| 66 | Ce que voient les utilisateurs | Avertissement systématique sur chaque réponse du copilote (`ai.disclaimer` : contenu généré par une IA, à vérifier, ni avis juridique, fiscal ni comptable) ; information sur l'IA avant la première génération ; avertissement sur les données sensibles près du champ de description ; marque `ai_generated` sur tout élément issu du copilote, même modifié. Textes à faire valider par le juriste (J2 à J5). |
| 67 | Agenda et accueil | Échéances des étapes et des tâches des workspaces modifiables dans l'agenda unifié **et l'abonnement** (titres et dates). Parcours d'accueil par rôle calculé sur les données réelles, **sans email** (ni relance d'accueil ni rappel de tâche en V1). |
| 68 | Comparateur de devis | **Pas d'IA dans le comparateur** : elle enverrait des données d'autres utilisateurs (identité du prestataire, prix). Le comparateur à règles du lot 3 reste inchangé. |
| 69 | Brouillon Directory | Un workspace peut créer un **brouillon** de projet Directory (titre, description, catégorie, pays, budget) ; il n'est jamais publié sans l'action habituelle de l'utilisateur, et il devient alors signalable comme tout projet. Un workspace peut aussi être relié à un projet existant de l'utilisateur. |
| 70 | Hébergement et démarches | **L'IA ne doit pas être activée sur l'hébergement mutualisé** (délai d'exécution) : appel synchrone de 45 s au plus ; `security:check-config` signale **en erreur** une IA activée avec un `max_execution_time` inférieur au délai d'appel plus 15 s de marge, et l'API refuse l'appel (503) dans ce cas. Hors du code : analyse d'impact (AIPD), politique de confidentialité, mesures de maîtrise de l'IA pour l'équipe (règlement sur l'IA, art. 4), validation des points J1 à J11 de `workspaces-rules.md`. |

Décisions techniques du plan (décision 20) : nouveau module `Workspaces` ; brique du noyau
`App\Support\Ai` (contrat `AiClient`, pilotes `disabled`, `fake` et `mistral`, passerelle
`AiGateway` qui vérifie la disponibilité, réserve le coût sous verrou et journalise sans contenu ;
tables `ai_usages`, `ai_budgets`, `ai_usage_stats`) ; coûts en micro-dollars entiers ; sortie
JSON imposée par un schéma puis **nettoyée par le serveur** (texte brut, liens retirés,
catégories filtrées, longueurs bornées ; réponse invalide : 502 `ai_invalid_response`, non
décomptée) ; appel synchrone avec un verrou par utilisateur (409 `generation_in_progress`) ;
réponse gardée comme **suggestion** que l'utilisateur applique ; brique du noyau
`App\Support\Onboarding` (contrat `OnboardingStep`, un adaptateur par module déclaré dans
`config/onboarding.php`) ; Actions publiques Directory `CreateDraftProject` et
`DescribeOwnProject` ; adaptateur d'agenda `WorkspacesAgendaSource` ; classes internes
`WorkspacesPersonalData` et `AiUsagePersonalData` pour l'export du lot 11 ; aucun texte d'un
workspace n'est visible d'autrui, donc aucun nouvel adaptateur de modération.

## Décisions du frontend (validées le 2026-11-20)

L'utilisateur a fourni les maquettes Figma (fichier « Crevia ») : accueil, tous les projets, détail
d'un projet, connexion, choix du profil, inscription, publication d'un projet en 4 étapes et
confirmation, articles (catalogue, détail, rédaction), événements (catalogue, détail, proposer un
webinar, tableau de bord). Les écarts entre les maquettes et le backend sont tranchés ainsi.

| # | Sujet | Décision |
|---|---|---|
| 71 | Démarrage et vitrine publique des projets | Frontend démarré maintenant (décision 2 modifiée), hébergé sur **Vercel** (dossier racine `frontend`), API sur Hostinger puis le VPS. Les maquettes montrent les projets aux visiteurs : **vitrine publique limitée** `GET /public/projects` et `GET /public/projects/{uuid}` (titre, catégorie, budget, pays, travail à distance, date de publication) ; **jamais la description, la ville, la date de début ni rien du porteur**. Accès anticipé Premium respecté (un projet n'entre dans la vitrine qu'après sa fenêtre de 24 h), projets masqués exclus, limite par IP `public-projects` (30/min, 1 000/j). La description complète et la proposition restent réservées aux prestataires connectés (`GET /projects`, inchangé). Le porteur est prévenu à la publication que titre, catégorie et budget sont publics. À reporter dans les CGU et la politique de confidentialité (juriste). |
| 72 | Contact | **Messagerie Loopartner seulement** : le choix « Adresse e-mail visible par les prestataires » de l'étape 4 est retiré ; l'email d'un utilisateur n'est jamais montré (règle inchangée). |
| 73 | Inscription | Adaptée au backend : « Client » ouvre le choix **Particulier / Entrepreneur** ; « Pseudo » devient **Nom affiché** ; **pas de connexion Google** pour l'instant (nouveau paquet et nouveau service : à présenter plus tard) ; acceptation des versions en vigueur des CGU et de la politique (décision 14) ; pays demandé. Connexion par email seulement (pas de pseudo). |
| 74 | Contenus des maquettes non conformes à la réalité | Retirés ou reformulés tant qu'ils ne sont pas vrais : « 500+ prestataires vérifiés », « Prestataires vérifiés » (devient « Professionnels déclarés »), « Messages traduits » (devient « Messagerie sécurisée »), carte « réseau mondial » (plateforme France et UE, décision 12), « +1500 experts », badge « Profil vérifié » (le badge « Entreprise immatriculée vérifiée » arrive au lot 6), nombres d'offres, de vues et d'interactions et avatars des proposants (non exposés par l'API), « profil du client réservé aux prestataires abonnés » (le nom est révélé à l'acceptation, décision 53), lien de visio « communiqué 15 minutes avant » (il est visible des inscrits dans leur espace). Les **photos de personnes** des maquettes (banques d'images, dont une marquée shutterstock) ne sont pas reprises : licence commerciale et accord des personnes à vérifier, et elles laisseraient croire à de vrais experts. |

Décisions techniques (décision 20) : Next.js 16 (App Router) et TypeScript, **sans framework CSS**
(feuille `globals.css` tirée des maquettes) ; polices Outfit et Inter auto-hébergées
(`@fontsource-variable/*`, aucun appel à Google Fonts) ; pages publiques rendues côté serveur
(`force-dynamic`, aucune donnée figée au build), pages connectées côté navigateur ; client
`src/lib/api.ts` (cookie CSRF de Sanctum, `X-XSRF-TOKEN`, erreurs à code stable, 403
`consent_required` ouvrant la fenêtre d'acceptation des CGU) ; redirections `/login`,
`/forgot-password`, `/reset-password` (liens des emails de l'API) vers les pages françaises ;
nouveau job « Frontend (Next.js) » dans la CI (ESLint, TypeScript, `npm audit`, build).
Paquets npm installés : `next`, `react`, `react-dom`, `@fontsource-variable/outfit`,
`@fontsource-variable/inter` et, en développement, `typescript`, `eslint`, `eslint-config-next`,
`@types/*`.

## Décisions du lot 6 : avis, ressources et coffre-fort (validées le 2026-11-22)

Plan présenté puis validé par l'utilisateur le 2026-11-22 (décision 20). Les textes destinés aux
utilisateurs (information sur les avis, libellé et explication du badge, partage de documents) et
la durée de conservation des avis restent **à faire valider par le juriste**.

| # | Sujet | Décision |
|---|---|---|
| 75 | Avis : preuve de l'interaction | Un client (particulier ou entrepreneur) ne note un prestataire qu'après une **interaction professionnelle vérifiable** (décision 6) : un **devis accepté** (Marketplace) **ou un rendez-vous confirmé et terminé** (Appointments) dans leur mise en relation. Un seul avis par mise en relation. La méthode de vérification et sa date sont affichées près de l'avis (« après un devis accepté le… »). |
| 76 | Avis : contenu et publication | Note de 1 à 5, texte de 50 à 2 000 caractères (texte brut), date de la prestation. **Publié tout de suite**, signalable et modéré ensuite (adaptateurs `review` et `review_response`, module Moderation). Le prestataire peut publier **une réponse publique**. Affichage du plus récent au plus ancien, moyenne et nombre d'avis, **aucun classement payant ni masquage** des avis négatifs (décision 18). Informations du Code de la consommation (article L111-7-2 et articles réglementaires D111-16 et suivants, numérotation à vérifier sur Légifrance) près des avis et dans une rubrique dédiée. Suppression du compte du client : ses avis sont supprimés. Durée de conservation en configuration, à faire fixer par le juriste. |
| 77 | Ressources | Guides et modèles (PDF, Word, Excel) **déposés seulement par l'équipe** (admin ou rédacteur avec 2FA de session), publics ou réservés au Premium (décision 33) ; téléchargement par un lien signé de 5 minutes, depuis un disque privé. |
| 78 | Coffre-fort documentaire | Disque **privé**, fichier **chiffré avant le stockage**, rattaché à une entreprise du suivi administratif (et facultativement à une échéance) ; PDF, JPEG et PNG vérifiés sur leur contenu, **10 Mo** par fichier ; place **100 Mo en gratuit, 1 Go en Premium** (`config/entitlements.php`) ; date d'expiration facultative avec rappel par email (Premium) ; document d'autrui : 404, admins compris ; supprimé avec le compte et inclus dans l'export. Hébergement : envois de 12 Mo au moins (`upload_max_filesize`, `post_max_size`). |
| 79 | Dossier partageable et badge | **Dossier entreprise partageable** (Premium) : documents choisis, lien temporaire (30 jours au plus) et révocable, seule l'empreinte du jeton est stockée (comme `FeedToken`) ; lien expiré ou révoqué : 404. **Badge « Entreprise immatriculée vérifiée »** : SIREN de l'identité professionnelle contrôlé par l'**API publique de recherche d'entreprises de l'État** (`recherche-entreprises.api.gouv.fr`, sans clé), en file d'attente, jamais pendant la requête de l'utilisateur ; revérification tous les 90 jours ; une panne de l'API ne retire jamais un badge ; l'explication affichée dit qu'il atteste **seulement l'immatriculation, jamais la qualité du travail**. Colonnes `registration_*` créées au lot 3. |
