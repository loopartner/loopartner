# Loopartner V1 2027 — feuille de route

Lots de développement de la V1 commercialisable, issus de l'audit du 2026-11-16. Décisions :
`docs/v1/DECISIONS.md`.

**Règles pour chaque lot**

- Aucun lot ne commence sans validation explicite de son **plan détaillé** (tables, endpoints,
  autorisations, limites, tests, risques).
- Les listes de contrôle de `CLAUDE.md` s'appliquent : sécurité (points 1 à 8) et migrations
  (MySQL 8, MariaDB 10.4 et plus, SQLite).
- Un lot n'est terminé que si `php artisan test`, `vendor/bin/pint --test`,
  `vendor/bin/phpstan analyse --memory-limit=1G`, `composer security` et
  `php artisan migrate --pretend` passent, et si `CLAUDE.md`, `SECURITY.md` et ce fichier sont à
  jour.
- **Une fonctionnalité non terminée n'est jamais exposée** (route absente ou désactivée), et rien
  n'est présenté comme terminé s'il ne l'est pas.
- Commits séparés et lisibles ; jamais de commit sur `main`, jamais de push sans demande.

**Frontend** : Next.js et TypeScript dans `frontend/`, **démarré une fois tous les lots backend
terminés, Billing compris** (décision 2 modifiée le 2026-11-16), puis accessibilité RGAA et
parcours de bout en bout.

**Plans de lot** : un plan est toujours présenté avant de coder. Les décisions purement techniques
y sont tranchées et notées ; seules les questions d'argent, de droit, de durées de conservation,
de nouveaux paquets et ce que les utilisateurs voient ou subissent attendent une validation
(décision 20).

| Lot | État |
|---|---|
| 0. Stabiliser Compliance | **terminé et fusionné dans `main`** le 2026-11-16 (avec la correction des noms d'index, `8e12edb`) |
| 1. Socle transverse | **terminé et fusionné dans `main`** le 2026-11-16 |
| 2. Signalements et modération (DSA) | **terminé et fusionné dans `main`** le 2026-11-16 (décisions 21 à 31) |
| 3. Marketplace | **terminé et fusionné dans `main`** le 2026-11-17 (décisions 36 à 51) ; reste hors du code : validations du juriste et de l'expert-comptable |
| 4. Rendez-vous et agenda | **codé sur `feature/rendez-vous`** le 2026-11-17 (décisions 52 à 59) ; reste la validation du juriste, puis la fusion dans `main` |
| 5 à 9 | à planifier |
| 10. Billing | plan validé dans son principe le 2026-11-16 ; développé à la fin du backend, juste avant le durcissement |
| 11. Durcissement avant la bêta | à planifier |

## Lot 0 — Stabiliser le module Compliance

Commit 7 (suppression de compte, minimisation, tests de sécurité) ; brouillons des règles
françaises 2027 et commande `compliance:import-rules` ; données de démonstration ; documentation
(CLAUDE.md, SECURITY.md) ; détection d'une base de démonstration en production ; feuille de route
et décisions ; contrôles finaux.

**Critères d'acceptation**
- Tous les contrôles verts, branche propre.
- Tableau des règles françaises : valeurs remplies avec leur source lue et sa date, valeurs à
  compléter avec la source à consulter ; aucune valeur venue de mémoire.
- Aucune règle importée n'est visible ni vérifiable tant qu'elle est incomplète.

**Reste hors du code** : vérification des règles 2027 par un expert-comptable. Tant qu'elle n'est
pas faite, aucun pays n'est proposé et le module reste invisible des utilisateurs.

## Lot 1 — Socle transverse

Intégration continue (tests sur SQLite **et sur de vrais serveurs MariaDB et MySQL**, Pint,
PHPStan, audit des dépendances) ; consentements et CGU versionnées ; brique de notifications et
préférences (noyau) ; droits par fonctionnalité (`GetEntitlements`, modèle de la décision 5
révisée) derrière `IsPremium`, sans changer le comportement du Blog ni de Compliance ; double
authentification obligatoire pour l'équipe. Décisions 13 à 17.

**Critères d'acceptation**
- L'intégration continue (GitHub Actions) s'exécute à **chaque push, sur toutes les branches**, et
  signale tout échec ; le projet n'utilise pas de pull request : l'utilisateur fusionne lui-même
  en local, après un résultat vert. Les migrations réelles et les tests passent sur MariaDB 10.4,
  une version récente de MariaDB et MySQL 8.
- L'inscription est impossible sans accepter la version en vigueur des CGU et de la politique de
  confidentialité ; chaque acceptation est datée et versionnée.
- Blog et Compliance inchangés (tous leurs tests existants verts).
- Un membre de l'équipe sans 2FA ne peut pas utiliser une fonction d'administration.

## Lot 2 — Signalements et modération (DSA)

**Terminé et fusionné dans `main` le 2026-11-16.**

Signalement générique de tout contenu public ; file d'administration ; décisions motivées ;
recours ; journal d'audit ; alignement d'Events sur les briques de modération du noyau.

**Critères d'acceptation**
- Tout contenu public (profil, offre, événement, article, avis) est signalable.
- Chaque décision est motivée, notifiée à l'auteur, auditée, et peut faire l'objet d'un recours.
- 401, 403 et 404 testés sur chaque route ; aucune donnée personnelle dans le canal security.

**Réalisé** (décisions 21 à 31 ; règles et textes : `docs/v1/moderation-rules.md`)
- Module `Moderation` : signalement de tout contenu public avec ou sans compte (profil, projet,
  événement, annonce, article ; messages et demandes depuis la messagerie), accusé de réception,
  suivi par jeton, signalements urgents (alerte immédiate, 24 h), file d'administration,
  décisions motivées (exposé des motifs complet), levée, trace d'une transmission aux autorités,
  recours de l'auteur et du signalant, purge, récapitulatif aux admins.
- Contrat du noyau `ModerationTarget` : offres et avis n'existent pas encore ; un test exige un
  adaptateur pour chaque nouvelle page publique de contenu (lots 3 et 6).
- Events et Blog : décisions existantes reprises en exposés des motifs, comportement inchangé
  (tests existants inchangés) ; Events aligné sur `VersionGuard`, `LinkInspection` et
  `ActionThrottle`.

**Reste hors du code** : validation par le juriste (`moderation-rules.md`, sections marquées) ;
rédaction des clauses de modération des CGU (art. 14) et report de leurs numéros dans
`config/moderation.php` (`security:check-config` l'exige en production).

## Lot 3 — Marketplace

Offres des prestataires ; traçabilité des professionnels de la place de marché (DSA art. 30) ;
devis structurés (lignes, quantités, prix en centimes, validité, acceptation, PDF), TVA lue dans
des règles en base. Premium (décision 33) : **comparateur de devis** pour le porteur de projet et
**accès anticipé de 24 heures** aux nouveaux projets pour les prestataires Premium.

**Critères d'acceptation**
- Les offres sont signalables : adaptateur `ModerationTarget` déclaré dans `config/moderation.php`
  et page publique ajoutée à `tests/Feature/Moderation/RestrictionSurfacesTest.php` (lot 2).
- Traçabilité des professionnels (art. 30 à 32) : non obligatoire pour une petite entreprise
  (art. 29) ; à trancher au plan du lot avec le juriste.
- Devis calculé en entiers ; taux de TVA issu d'une règle vérifiée, jamais du code.
- Le PDF d'un devis ne contient aucune donnée d'un autre utilisateur ; 404 sur le devis d'autrui.
- **Comparateur de devis** (particuliers et entrepreneurs) : les devis reçus pour un même projet
  s'affichent côte à côte, avec les éléments manquants et les questions à poser, calculés par des
  **règles explicables, sans IA** (chaque élément affiche sa raison) ; réservé au porteur du
  projet (404 sinon) ; gratuit : lecture de chaque devis ; limites dans
  `config/entitlements.php`. La brique d'IA du lot 5 pourra l'enrichir ensuite (facultatif,
  quotas de la décision 34) : le comparateur doit rester complet sans elle.
- **Accès anticipé** : pendant 24 heures (configurable) après sa publication, un projet n'est
  visible que des prestataires Premium (liste, détail et propositions ; testé dans les deux
  sens) ; l'ordre des résultats est le même pour tous (aucun classement payant, décision 18) ;
  annonce dans les CGU et information du porteur de projet validées par le juriste.

**Plan détaillé** (validé le 2026-11-17 ; décisions 36 à 50 ; textes et points à faire valider :
`docs/v1/marketplace-rules.md`)

- **Module `Marketplace`** (ajouté au test d'architecture) : identité professionnelle, offres,
  devis, comparateur, PDF. Actions publiques de lecture : Directory `GetMatchRequestContext` et
  `FindOwnProject`, Compliance `GetVatRates`, Accounts `PublishedProviderConstraint`.
- **Identité professionnelle** (`marketplace_seller_profiles`, `GET|PUT /me/seller-profile`) :
  dénomination, forme juridique, SIREN (forme contrôlée), registre, capital, TVA, adresse,
  coordonnées, assurance, médiateur, profession réglementée, autocertification versionnée ;
  colonnes de la vérification du lot 6 créées dès maintenant ; chiffrée en base ; champs manquants
  annoncés (`missing_for_offers`, `missing_for_quotes`, `missing_for_consumer_quotes`).
- **Offres** (`marketplace_offers`) : `/me/offers` (CRUD, `publish`, `unpublish`) ; public
  `GET /offers`, `GET /offers/{slug}`, `GET /providers/{slug}/offers` ; prix indicatif TTC ;
  qualité déclarée affichée ; visibles si l'offre et le profil sont publiés et non masqués ;
  adaptateur `offer` ; 20 publiées et 30 au total.
- **Devis** (`marketplace_quotes`, `marketplace_quote_lines`) : seulement dans une demande
  acceptée ; brouillon, envoi, révision (nouvelle version), retrait, acceptation (empreinte lue,
  double clic, demande expresse, certifications), refus, rétractation en ligne (consommateur,
  14 jours) ; numéro `D-AAAA-NNNN` par prestataire ; calcul en entiers (arrondi par taux) ; taux
  choisis par ligne parmi les règles `vat_rate` vérifiées ; snapshot et empreinte SHA-256 ; PDF
  (`GET /me/quotes/{uuid}/pdf`, les deux parties ; logo et mentions en Premium) ; signalement
  (adaptateur privé `quote`).
- **Comparateur** : `GET /me/projects/{uuid}/quotes` (gratuit), `…/quotes/comparison` et
  `…/comparison.pdf` (Premium) ; constats avec règle, gravité, raison et question.
- **Accès anticipé** : `config/directory.php` (`early_access_hours`), scope `browsableBy()` sur la
  liste, le détail, les propositions et le signalement ; `early_access_ends_at` pour le porteur.
- **Droits** (`config/entitlements.php`) : `marketplace.quote_comparison`,
  `marketplace.quote_comparison_export` (particulier, entrepreneur), `directory.project_early_access`,
  `marketplace.quote_branding` (prestataire).
- **TVA** : type de règle `vat_rate`, brouillons `fr-2027-vat.draft.json` sans valeur ; sans taux
  vérifié, 409 `vat_rates_unavailable`.
- **Emails** : `marketplace.quotes` (facultative), `marketplace.quote_decisions` (obligatoire, PDF
  du devis accepté en pièce jointe) ; jamais de montant ni de ligne dans le corps.
- **Tâches** : `marketplace:expire-quotes` (chaque heure), `marketplace:prune` (05:15).
- **Suppression de compte** (`ForgetUserMarketplace`) et minimisation (décision 47).
- **Paquet** : `dompdf/dompdf` ^3.1, installé au commit des PDF.
- **Commits** : Compliance (TVA) ; Directory (accès anticipé) ; identité professionnelle ;
  offres ; devis ; acceptation et emails ; PDF ; comparateur ; signalement, suppression, purge ;
  tests de sécurité ; démonstration ; documentation.

**Réalisé** (2026-11-17) : tout le plan ci-dessus, avec les précisions de l'utilisateur (PDF du
devis accepté joint à la confirmation, colonnes de la vérification du lot 6 créées, `dompdf/dompdf`
v3.1.6 installé avec `composer security` avant et après). Toutes les routes sont dans
`AuthorizationMatrixTest` ; les offres dans `RestrictionSurfacesTest`.

**Reste hors du code** : validation des points J1 à J18 (juriste) et E1 à E10 (expert-comptable)
de `docs/v1/marketplace-rules.md` ; vérification des taux de TVA 2027 (sans elle, aucun devis
n'est possible en production) ; rubrique d'information de la place de marché et clauses des CGU
(accès anticipé, Loopartner non partie au contrat) ; politique de confidentialité ; vérifier sur
Hostinger les extensions `dom` et `mbstring` et la mémoire disponible pour les PDF.

## Lot 4 — Rendez-vous et agenda

Rendez-vous natifs (créneaux, confirmation, annulation, rappels, `.ics`) ; agenda personnel unifié
(événements, échéances, rendez-vous) ; abonnement `.ics` unique.

**Critères d'acceptation**
- Aucun créneau réservé deux fois, même en cas de demandes simultanées.
- L'abonnement ne contient aucune donnée privée au-delà des titres et des dates.

**Plan détaillé** (validé le 2026-11-17 ; décisions 52 à 59 ; sources, textes et points à faire
valider : `docs/v1/appointments-rules.md`)

- **Module `Appointments`** (ajouté au test d'architecture) ; briques du noyau `Agenda`
  (contrat `AgendaSource`, adaptateurs Appointments, Events et Compliance), `LocalTime` (heure
  locale, trous et doublons d'heure) et `FeedToken` (jeton d'abonnement, Compliance lui délègue).
- **Tables** : `appointment_calendars` (une par prestataire, ligne verrouillée à chaque
  réservation), `appointment_types`, `appointment_availability_rules` (heure locale),
  `appointment_availability_exceptions`, `appointments` (cascade depuis `match_requests`),
  `appointment_slot_holds` (`slot_key` unique), `appointment_notes` (chiffrées, signalables),
  `appointment_reminders` (réservation avant l'envoi), `agenda_feeds` (noyau).
- **Endpoints** : réglages, types, plages, exceptions et aperçu des créneaux du prestataire ;
  réservation et proposition dans une relation acceptée (`/me/match-requests/{uuid}/…`) ; page
  publique Premium (`/providers/{slug}/booking`, `/providers/{slug}/appointments`) ;
  `/me/appointments` (liste, détail, confirmation, refus, annulation, report, `.ics`,
  signalement d'une note) ; agenda unifié `GET /me/agenda` ; abonnement unique
  `/me/agenda/feed` et `GET /agenda/{token}.ics` (titres et dates seulement).
- **Double réservation** : verrou du calendrier puis index unique, 409 `slot_unavailable` ;
  créneaux libérés à l'annulation, au refus, à l'expiration et au report (testés, index compris) ;
  test à deux connexions sur MariaDB et MySQL.
- **Fuseau et changements d'heure** : plages en heure locale (Europe/Paris par défaut), créneaux
  en temps réel ; testés autour des changements d'heure de 2026 et 2027.
- **Droits** (`config/entitlements.php`, prestataire) : `appointments.public_booking_page`,
  `active_types`, `weekly_ranges`, `upcoming_exceptions`, `horizon_days`,
  `upcoming_appointments`, `reminder_offsets`, `max_reminder_offsets` (décision 54).
- **Emails** : `appointments.bookings` (obligatoire, `.ics` joint à la confirmation) et
  `appointments.reminders` (facultative).
- **Tâches** : `appointments:send-reminders` (toutes les 5 minutes), `appointments:expire`
  (toutes les 15 minutes), `appointments:prune` (04:45).
- **Signalement** : adaptateurs `appointment_type` (public) et `appointment_note` (privé).
- **Suppression de compte** (`ForgetUserAppointments`), minimisation (décision 57), classe interne
  `AppointmentsPersonalData` pour l'export du lot 11 (décision 59).
- **Paquet** : aucun (`App\Support\Calendar\Ics` existant).
- **Commits** : documentation ; noyau ; Directory ; disponibilités et créneaux ; réservation ;
  annulation et report ; page publique ; emails et rappels ; `.ics` ; agenda unifié et
  abonnement ; signalement, suppression, purge, export interne ; tests de sécurité ;
  démonstration ; documentation finale.

**Réalisé** (2026-11-17) : tout le plan ci-dessus, avec les précisions de l'utilisateur (limite
des propositions de créneau du prestataire : 3 par relation et 30 au total ; `.ics` sans lieu
joint à la confirmation ; classe interne `AppointmentsPersonalData` ; tests de libération des
créneaux après annulation, refus, expiration, report accepté et refusé, index unique compris).
Toutes les routes sont dans `AuthorizationMatrixTest` et `AppointmentsAuthorizationTest` ; la
page de réservation publique dans `RestrictionSurfacesTest`. Limite mémoire des tests portée à
1 Go. Double réservation simultanée : testée par l'index unique sur tous les moteurs et par la
présence du verrou `FOR UPDATE` sur MySQL et MariaDB (la suite tourne dans une transaction par
test : pas de test à deux processus).

**Reste hors du code** : validation des points R1 à R11 de `docs/v1/appointments-rules.md`
(juriste) ; clauses des CGU (page de réservation Premium, annulation) et politique de
confidentialité (tableau de SECURITY.md) ; surveiller le quota d'emails Resend (deux
confirmations et au moins deux rappels par rendez-vous confirmé).

## Lot 5 — Workspaces et onboarding

Module Workspaces (privé par défaut) : objectifs, étapes, tâches, avancement, lien facultatif vers
une annonce Directory ; parcours d'onboarding. **Brique d'IA du noyau** (décision 34) et
**copilote de projet** (décision 33). Le plan du lot présente le choix du fournisseur et du
modèle d'IA.

**Critères d'acceptation**
- Parcours complet de l'inscription à la première tâche.
- Aucune donnée d'un workspace n'est publique sans action explicite ; 404 sur le workspace d'autrui.
- **Brique d'IA** : appels derrière une interface simulée dans les tests (aucun appel réseau
  réel) ; quotas par utilisateur et par plan dans `config/entitlements.php` ; suivi des coûts ;
  journal sans le contenu des échanges (testé) ; IA désactivable en configuration (fonctionnalité
  indisponible, testé) ; réponse de l'IA traitée comme une donnée non fiable (texte brut,
  catégories filtrées sur les catégories actives) ; politique de confidentialité mise à jour et
  validée par le juriste ; clés vérifiées par `security:check-config` quand l'IA est activée.
- **Copilote de projet** : à partir de la description d'un projet, feuille de route, catégories
  de prestataires utiles (reliées à `GET /providers`) et cahier des charges ; jamais d'avis
  juridique ou fiscal, avertissement systématique dans chaque réponse ; gratuit : une feuille de
  route, Premium : usage étendu avec quotas ; dépassement : 403 `premium_required` ou 429 selon
  le cas ; 404 sur le projet d'autrui.
- **Comparateur de devis** (facultatif, si retenu au plan du lot) : enrichissement par l'IA dans
  les quotas de la décision 34 ; IA désactivée ou quota atteint : le comparateur à règles du lot 3
  reste disponible et inchangé (testé).

## Lot 6 — Avis, ressources et coffre-fort documentaire

Avis après une interaction professionnelle vérifiable (droit de réponse, signalement,
modération) ; ressources (guides, modèles) et téléchargements signés ; coffre-fort documentaire
privé du module Compliance (disque privé, URL signées). Premium (décision 33) : **dossier
entreprise partageable** et **badge « Entreprise immatriculée vérifiée »**.

**Critères d'acceptation**
- Un avis est impossible sans interaction vérifiable ; la méthode de vérification est affichée.
- Les avis sont signalables (adaptateur `ModerationTarget`, lot 2) ; obligations d'information
  du Code de la consommation sur les avis en ligne à valider avec le juriste.
- Un document du coffre-fort n'est accessible qu'à son propriétaire (404 sinon), jamais par une
  URL publique, et il est supprimé avec le compte.
- **Dossier entreprise partageable** : documents rangés, lien de partage sécurisé (jeton dont
  seule l'empreinte est stockée), temporaire et révocable, qui ne donne accès qu'aux documents
  choisis ; lien expiré ou révoqué : 404 ; rappels d'expiration des documents (catégorie de
  notification déclarée) ; réservé au Premium selon `config/entitlements.php`.
- **Badge « Entreprise immatriculée vérifiée »** : vérification de l'immatriculation par l'API
  publique de recherche d'entreprises de l'État (appel simulé dans les tests, panne sans effet sur
  le reste du profil), date de la vérification gardée et revérification périodique ; l'explication
  affichée dit qu'il atteste **seulement l'immatriculation, jamais la qualité du travail** ;
  libellé et explication validés par le juriste ; adaptateur `ModerationTarget` inchangé pour le
  profil.

## Lot 7 — Recommandations

Règles simples et explicables (catégorie d'une tâche, prestataires, articles, événements,
parcours), possibilité de les désactiver. Premium (décision 33) : **radar d'opportunités**
(aides, concours, appels à projets, événements) pour les prestataires, les entrepreneurs et les
particuliers (porteurs de projets : aides à la création, concours, événements pour se lancer).
La source des aides, concours et appels à projets est présentée au plan du lot.

**Critères d'acceptation**
- Chaque recommandation affiche sa raison ; l'utilisateur peut les désactiver.
- **Radar d'opportunités** (particuliers, prestataires et entrepreneurs, opportunités adaptées
  au rôle) : opportunités personnalisées (raison affichée), dates limites
  ajoutées au calendrier et à l'abonnement `.ics` ; email dans une catégorie de notification
  facultative ; gratuit : un email par mois, Premium : chaque semaine et complet (fréquences en
  configuration) ; aucun email en double (envoi réservé avant l'envoi, comme Compliance).

## Lot 8 — Back-office et analytics

Gestion des utilisateurs, des catégories et des plans ; indicateurs agrégés côté serveur, sans
cookie de suivi. Premium (décision 33) : **statistiques des prestataires**.

**Critères d'acceptation**
- Aucune donnée personnelle dans les indicateurs ; chaque action d'administration est auditée.
- **Statistiques des prestataires** : agrégats côté serveur sans cookie de suivi (décision 8),
  visibles du seul prestataire concerné (404 sinon), sans donnée permettant d'identifier un
  visiteur ; version gratuite et Premium selon `config/entitlements.php`.

## Lot 9 — Compliance : évolutions du moteur avant vérification

À terminer **avant la bêta** et avant la vérification des règles 2027 par l'expert-comptable :
le moteur doit savoir représenter les règles réelles. Limites relevées au lot 0 :

1. Seuils appréciés sur l'année **N-1 ou N-2** (aujourd'hui : cumul de l'année en cours).
2. **Première déclaration de la micro-entreprise** (période particulière après la création).
3. Échéance au **« 31 décembre de l'année de création »** (ou de l'année suivante).
4. **Échéance qui dépend d'une autre** (date calculée à partir d'une autre échéance).
5. **Délai d'approbation fixé par les statuts** (date propre à l'entreprise).
6. **Report d'une échéance tombant un week-end ou un jour férié** (aujourd'hui : pas de report,
   la date vient de la règle).

**Critères d'acceptation**
- Chaque cas est représentable par des données (aucune date, aucun seuil dans le code) et testé.
- Les échéances déjà faites ou ignorées ne sont jamais modifiées par le recalcul.
- Les brouillons `fr-2027.draft.json` concernés sont mis à jour (valeurs lues avec leur source).

## Lot 10 — Billing

Dernier lot fonctionnel, juste avant le durcissement (décisions 4 et 5 révisées, 18, 19, 32
et 35 ; reporté le 2026-11-16 : Stripe est mis en place à la fin du backend). Offre **Gratuit et un seul
Premium**, au même prix pour tous les rôles, mensuel ou annuel ; avantages selon le rôle
(`GetEntitlements`). **Stripe** : Checkout hébergé (carte et 3-D Secure chez Stripe), portail
client, factures Stripe ; Loopartner vendeur (TVA OSS et factures, à valider avec
l'expert-comptable). **Essai gratuit de 30 jours** (configurable) avec carte dès le début,
0 € prélevé, une seule fois par personne (compte et empreinte de carte). Information avant la
souscription, **CGV** versionnées et acceptées avant tout abonnement, rappel avant la fin de
l'essai, information avant chaque renouvellement annuel, résiliation en quelques clics,
fonction de rétractation en ligne, relances de paiement Stripe, factures accessibles depuis le
compte, webhooks idempotents, suppression du compte avec annulation immédiate de l'abonnement.
Premiers avantages Premium des particuliers (limites de projets et de demandes en
configuration). **Prix** (décision 32) : 14,90 € TTC par mois ou 149 € TTC par an ; **prix
fondateur** (500 premiers abonnés, 9,90 € TTC par mois ou 99 € TTC par an, à vie tant que
l'abonnement n'est pas résilié) ; **parrainage** (un mois offert au parrain et au filleul) ;
**codes partenaires** (trois mois offerts) ; prix à confirmer avec l'expert-comptable. Brouillon
des textes destinés aux utilisateurs : `docs/v1/billing-rules.md` (à faire valider par un
juriste).

**Plan détaillé** (validé dans son principe le 2026-11-16, à reprendre tel quel ; décisions
du plan : décision 19)

- **Paquet** : `stripe/stripe-php` seul (SDK officiel), derrière une interface `StripeGateway`
  simulée dans les tests ; installé seulement au début de ce lot. Pas de `laravel/cashier`
  (trait `Billable` et colonnes sur `User`, tables et route `/stripe/webhook` propres, webhook
  synchrone sans idempotence par identifiant d'événement).
- **Module `Billing`** (ajouté au test d'architecture), activé par `BILLING_ENABLED` (false par
  défaut : routes en 404 tant que les CGV et les clés de production ne sont pas prêtes).
- **Premium payé** : Action publique d'Accounts `SyncPaidPremium` qui alimente `premium_grants`
  (`source` `trial` (nouvelle valeur) ou `payment`, `external_reference` = abonnement Stripe,
  `ends_at` = fin de l'essai ou de la période payée, plus le délai de grâce). `IsPremium`,
  `GetEntitlements` et la fin du premium restent inchangés.
- **Montants** : lus sur le prix Stripe (TVA incluse), en cache une heure ; configuration :
  `STRIPE_PRICE_MONTHLY`, `STRIPE_PRICE_YEARLY`, `billing.trial_days` (30),
  `billing.trial_reminder_days` (7), `billing.renewal_notice_days` (45, entre 31 et 89 :
  article L215-1), `billing.grace_days` (3).
- **Tables** (datetime UTC, noms de moins de 64 caractères) : `billing_customers` (`user_id`
  unique, null à la suppression du compte, `stripe_customer_id`) ; `billing_subscriptions`
  (`uuid`, `user_id`, identifiants Stripe de l'abonnement, du client et du prix, `interval`,
  `status`, `trial_ends_at`, `current_period_end`, `cancel_at_period_end`, `canceled_at`,
  `ended_at`, `withdrawal_ends_at`) ; `billing_trial_claims` (empreinte de carte hachée
  SHA-256, index unique, `user_id`, `claimed_at`) ; `billing_webhook_events` (`stripe_event_id`
  unique, `type`, `object_id`, `status`, `attempts`, sans le contenu ; purge à 90 jours) ;
  `billing_notices` (index unique abonnement, type, période : aucun email en double).
- **CGV** : nouveau type `sales` de `LegalDocumentType`, exigé avant tout abonnement, pas à
  l'inscription ; le blocage `consent_required` reste limité aux CGU et à la politique de
  confidentialité.
- **Endpoints** (`auth:sanctum` et `verified` sauf mention) : `GET /billing/plans` (public) ;
  `GET /me/billing/preview?interval=` (prix TTC, périodicité, droit à l'essai, `charged_now`,
  date du premier prélèvement, date du rappel, reconduction, comment annuler, CGV en vigueur) ;
  `POST /me/billing/checkout` (`interval`, `sales_terms_version` acceptée et tracée,
  `immediate_execution` exigé sans essai ; URL de Stripe Checkout ; 409 si un abonnement est en
  cours) ; `GET /me/billing` ; `POST /me/billing/cancel` et `/resume` ; `POST /me/billing/withdraw`
  (fonction de rétractation en ligne, 14 jours) ; `POST /me/billing/portal` ;
  `GET /me/billing/invoices` (factures Stripe) ; `POST /billing/stripe/webhook` (public,
  signature, limiteur par IP, corps de 256 Ko au plus).
- **Stripe Checkout** : mode abonnement, `trial_period_days` si l'utilisateur y a droit, carte
  toujours demandée, acceptation des CGV obligatoire, texte du brouillon au-dessus du bouton,
  Stripe Tax et numéro de TVA des professionnels, langue de l'utilisateur, clé d'idempotence.
- **Un essai par personne** : aucun essai pour un compte qui a déjà eu un essai ou un abonnement
  payé ; à `checkout.session.completed`, une carte dont l'empreinte a déjà servi à un essai
  entraîne l'annulation immédiate de l'abonnement (rien n'est prélevé) et un email qui propose
  de souscrire sans essai.
- **Webhooks** : signature vérifiée, insertion par identifiant d'événement (doublon ignoré),
  tâche en file d'attente qui relit l'objet à jour chez Stripe puis synchronise (ordre
  d'arrivée sans importance, rejeu sans effet). Événements : `checkout.session.completed` et
  `.expired`, `customer.subscription.created`, `.updated`, `.deleted`, `invoice.paid`,
  `invoice.payment_failed`, `charge.refunded`, `charge.dispute.created`, `customer.deleted`.
- **Tâches planifiées** : `billing:send-notices` (rappel de fin d'essai, information avant
  renouvellement annuel), `billing:sync` (webhooks manqués), `billing:prune`.
- **Échec de paiement** : relances de Stripe ; Premium jusqu'à la fin de la période payée plus
  `billing.grace_days`, puis plan gratuit sans perte de données.
- **Suppression du compte** : `DeleteAccount` appelle l'Action publique Billing
  `CancelForAccountDeletion` avant sa transaction (annulation immédiate et suppression du client
  Stripe ; factures conservées chez Stripe) ; Stripe injoignable : 503, compte non supprimé.
- **Notifications** : catégorie obligatoire `billing.account` (rappel, renouvellement,
  résiliation, rétractation, échec de paiement, essai refusé). Textes : `docs/v1/billing-rules.md`.
- **Avantages des particuliers** : `Project::MAX_PUBLISHED_PER_USER`,
  `MatchRequest::MAX_PENDING_SENT` et la limite de demandes par jour passent dans
  `config/entitlements.php` (gratuit : 10, 30, 20 ; premium : 25, 60, 50). La limite par jour est
  comptée dans l'Action de Directory (le noyau ne dépend d'aucun module).
- **Sécurité** : uniquement des identifiants Stripe et l'empreinte hachée en base ; clés
  `STRIPE_SECRET` (`sk_live_` ou `rk_live_`), `STRIPE_WEBHOOK_SECRET` et prix dans `.env.example` ;
  `security:check-config` échoue sur une clé manquante, une clé de test en production ou
  l'absence de CGV en vigueur avec Billing activé ; limiteurs `billing-checkout`,
  `billing-actions`, `billing-invoices`, `stripe-webhook` ; canal security sans carte,
  empreinte ni montant ; tests sans réseau (`StripeGateway` simulé, signatures calculées
  localement, échec si le vrai client est appelé).
- **Factures et TVA** : factures Stripe ; Stripe Tax activé, prix TTC identique dans toute l'UE ;
  conservation des données de facturation proposée à 10 ans, détachées du compte supprimé ;
  facturation électronique des clients professionnels et e-reporting : à valider avec
  l'expert-comptable.
- **Rétractation et remboursement** : proposition du § 8 de `docs/v1/billing-rules.md`, à faire
  valider par le juriste.
- **Prix fondateur, parrainage et codes partenaires** (décision 32, ajoutés le 2026-11-16) : à
  détailler dans le plan du lot avant de coder : mécanisme Stripe (prix dédiés, coupons, codes
  promotionnels, crédits sur le solde du client), décompte des 500 places sous verrou, conditions
  (cumul avec l'essai et entre offres, plafond de parrainages, prévention de l'auto-parrainage),
  information des abonnés avant toute hausse de prix, textes des CGV.

**Critères d'acceptation**
- Aucun prix, montant, durée d'essai, quota ni taux dans le code ; identifiants des prix Stripe
  en configuration.
- Aucune donnée de carte sur nos serveurs ; seuls des identifiants Stripe sont stockés.
- Un webhook rejoué ou reçu dans le désordre n'a aucun effet en double (signature vérifiée,
  événements déjà traités ignorés) ; route limitée en débit.
- Une personne ne bénéficie qu'une fois de l'essai (même compte, ou même carte sur un autre
  compte, testé) ; sans droit à l'essai, l'utilisateur voit avant de payer qu'il est débité tout
  de suite.
- L'écran d'information avant souscription donne le prix TTC, la périodicité, la durée de
  l'essai, la date exacte du premier prélèvement, la date du rappel, la reconduction, la façon
  d'annuler et le lien vers les CGV ; les CGV en vigueur sont acceptées et tracées avant tout
  abonnement.
- Rappel de fin d'essai (7 jours avant, configurable) et information avant chaque renouvellement
  annuel, sans doublon, dans une catégorie de notification obligatoire.
- Résiliation en quelques clics depuis le compte et le portail Stripe, avec email de
  confirmation ; annulée pendant l'essai : aucun prélèvement ; après : Premium jusqu'à la fin de
  la période payée.
- La fin d'un paiement ramène aux droits gratuits sans perte de données ; la suppression du
  compte annule l'abonnement chez Stripe immédiatement.
- `security:check-config` échoue sur une clé Stripe manquante ou une clé de test en production.
- Aucun appel réseau réel à Stripe dans les tests.
- **Prix fondateur** : jamais plus de places que le nombre configuré, même en cas de souscriptions
  simultanées (testé) ; le prix est conservé tant que l'abonnement n'est pas résilié, perdu
  après une résiliation.
- **Parrainage** : un mois offert au parrain et au filleul une seule fois par filleul, jamais à
  soi-même ; **codes partenaires** : trois mois offerts, code inconnu, expiré ou déjà utilisé
  refusé ; durées et valeurs en configuration.
- Les abonnés sont informés avant toute hausse de prix, dans une catégorie de notification
  obligatoire.

## Lot 11 — Durcissement avant la bêta

Performance (requêtes N+1, index, recherche), tests de charge, sauvegardes et restauration,
supervision, revue de sécurité externe ; préparation du VPS ou de l'hébergement Laravel géré ;
**documentation de l'API au format OpenAPI** (le paquet est proposé dans le plan du lot).

**Critères d'acceptation**
- Objectifs de temps de réponse atteints ; restauration d'une sauvegarde testée.
- Procédure de rotation d'`APP_KEY` testée (voir SECURITY.md).
- **Documentation OpenAPI** de toutes les routes `api/*` (paramètres, réponses, codes d'erreur
  dont `premium_required` et `consent_required`), vérifiée contre le routeur par un test (aucune
  route oubliée) ; elle n'expose ni route d'administration en production, ni exemple contenant
  une donnée réelle.
