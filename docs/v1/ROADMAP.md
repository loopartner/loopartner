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

**Frontend** : Next.js et TypeScript dans `frontend/`, **démarré après le lot 1**, puis développé
en parallèle des lots 2 et suivants (accessibilité RGAA, parcours de bout en bout).

| Lot | État |
|---|---|
| 0. Stabiliser Compliance | terminé le 2026-11-16, en attente de validation (branche `feature/compliance-core`, non fusionnée) |
| 1 à 10 | à planifier |

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

Intégration continue (tests sur SQLite **et sur un vrai serveur MariaDB**, Pint, PHPStan, audit
des dépendances) ; consentements et CGU versionnées ; brique de notifications et préférences
(noyau) ; droits par fonctionnalité (`Entitlements`) derrière `IsPremium`, sans changer le
comportement du Blog ni de Compliance ; double authentification obligatoire pour l'équipe.

**Critères d'acceptation**
- L'intégration continue bloque une pull request en échec ; les migrations passent sur MariaDB.
- L'inscription est impossible sans accepter la version en vigueur des CGU et de la politique de
  confidentialité ; chaque acceptation est datée et versionnée.
- Blog et Compliance inchangés (tous leurs tests existants verts).
- Un membre de l'équipe sans 2FA ne peut pas utiliser une fonction d'administration.

## Lot 2 — Signalements et modération (DSA)

Signalement générique de tout contenu public ; file d'administration ; décisions motivées ;
recours ; journal d'audit ; alignement d'Events sur les briques de modération du noyau.

**Critères d'acceptation**
- Tout contenu public (profil, offre, événement, article, avis) est signalable.
- Chaque décision est motivée, notifiée à l'auteur, auditée, et peut faire l'objet d'un recours.
- 401, 403 et 404 testés sur chaque route ; aucune donnée personnelle dans le canal security.

## Lot 3 — Marketplace

Offres des prestataires ; traçabilité des professionnels de la place de marché (DSA art. 30) ;
devis structurés (lignes, quantités, prix en centimes, validité, acceptation, PDF), TVA lue dans
des règles en base.

**Critères d'acceptation**
- Devis calculé en entiers ; taux de TVA issu d'une règle vérifiée, jamais du code.
- Le PDF d'un devis ne contient aucune donnée d'un autre utilisateur ; 404 sur le devis d'autrui.

## Lot 4 — Rendez-vous et agenda

Rendez-vous natifs (créneaux, confirmation, annulation, rappels, `.ics`) ; agenda personnel unifié
(événements, échéances, rendez-vous) ; abonnement `.ics` unique.

**Critères d'acceptation**
- Aucun créneau réservé deux fois, même en cas de demandes simultanées.
- L'abonnement ne contient aucune donnée privée au-delà des titres et des dates.

## Lot 5 — Workspaces et onboarding

Module Workspaces (privé par défaut) : objectifs, étapes, tâches, avancement, lien facultatif vers
une annonce Directory ; parcours d'onboarding.

**Critères d'acceptation**
- Parcours complet de l'inscription à la première tâche.
- Aucune donnée d'un workspace n'est publique sans action explicite ; 404 sur le workspace d'autrui.

## Lot 6 — Billing

Choix du prestataire de paiement (après vérification du calendrier de la facturation électronique
française) ; plans Entrepreneur Free/Plus et Prestataire Free/Pro/Business (prix, quotas, droits en
données) ; abonnements, webhooks, factures, relances ; droits liés aux plans.

**Critères d'acceptation**
- Un webhook rejoué n'a aucun effet en double (traitement idempotent, signature vérifiée).
- La fin d'un paiement ramène aux droits gratuits sans perte de données.
- Aucun prix, quota ni taux dans le code.

## Lot 7 — Avis, ressources et coffre-fort documentaire

Avis après une interaction professionnelle vérifiable (droit de réponse, signalement,
modération) ; ressources (guides, modèles) et téléchargements signés ; coffre-fort documentaire
privé du module Compliance (disque privé, URL signées).

**Critères d'acceptation**
- Un avis est impossible sans interaction vérifiable ; la méthode de vérification est affichée.
- Un document du coffre-fort n'est accessible qu'à son propriétaire (404 sinon), jamais par une
  URL publique, et il est supprimé avec le compte.

## Lot 8 — Recommandations

Règles simples et explicables (catégorie d'une tâche, prestataires, articles, événements,
parcours), possibilité de les désactiver.

**Critères d'acceptation**
- Chaque recommandation affiche sa raison ; l'utilisateur peut les désactiver.

## Lot 9 — Back-office et analytics

Gestion des utilisateurs, des catégories et des plans ; indicateurs agrégés côté serveur, sans
cookie de suivi.

**Critères d'acceptation**
- Aucune donnée personnelle dans les indicateurs ; chaque action d'administration est auditée.

## Lot 10 — Durcissement avant la bêta

Performance (requêtes N+1, index, recherche), tests de charge, sauvegardes et restauration,
supervision, revue de sécurité externe ; préparation du VPS ou de l'hébergement Laravel géré.

**Critères d'acceptation**
- Objectifs de temps de réponse atteints ; restauration d'une sauvegarde testée.
- Procédure de rotation d'`APP_KEY` testée (voir SECURITY.md).
