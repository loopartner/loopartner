# Textes légaux et RGPD (projets à faire valider)

> **PROJETS DU 2026-11-22, RÉDIGÉS PAR L'ÉQUIPE TECHNIQUE, À FAIRE RELIRE ET VALIDER PAR UN
> JURISTE AVANT TOUTE MISE EN LIGNE.** Ils décrivent le fonctionnement réel de la Plateforme
> (code, `config/security.php`, décisions de `docs/v1/DECISIONS.md`) et ne constituent pas un
> avis juridique. Les informations marquées `[À COMPLÉTER]` (identité de la société, hébergeurs,
> médiateur, garanties de transfert…) ne sont jamais écrites de mémoire.

| Fichier | Document | Publication |
|---|---|---|
| `cgu.md` | Conditions générales d'utilisation (`terms`) | `php artisan legal:publish` (versionné, acceptation obligatoire) |
| `politique-de-confidentialite.md` | Politique de confidentialité (`privacy`) | `php artisan legal:publish` |
| `cgv-premium.md` | CGV de l'abonnement Premium | page `/legal/sales` du site ; versionnement et acceptation au lot 10 (Billing) |
| `cookies.md` | Cookies et traceurs | page `/legal/cookies` |
| `mentions-legales.md` | Mentions légales (LCEN) | page `/legal/notice` |
| `registre-des-traitements.md` | Registre des traitements (art. 30) | **interne, jamais publié** |

## Format

Texte brut (l'API refuse le HTML) avec un petit en-tête (`type`, `title`, `version`) et quatre
conventions : `## ` titre, `### ` sous-titre, `- ` élément de liste, ligne vide entre deux
paragraphes. Le frontend les affiche avec `LegalText`, sans jamais interpréter de HTML.

Après chaque modification : `node scripts/build-legal.mjs` depuis `frontend/` régénère
`frontend/src/content/legal.ts` (pages du site et mode démonstration). Le script refuse un texte
qui contient une balise HTML.

## Publication des CGU et de la politique (après validation)

1. Traduire les textes validés en anglais (`docs/legal/en/<fichier>.md`, même format) : l'API
   exige les deux langues.
2. `node scripts/build-legal.mjs --json <version> <AAAA-MM-JJ>` écrit
   `backend/database/data/legal/<type>-<version>.json` au format de `legal:publish`.
3. En production : `php artisan legal:publish database/data/legal/terms-<version>.json` puis la
   même commande pour `privacy` (une version publiée n'est plus jamais modifiée).
4. Renseigner dans `config/moderation.php` (`terms_clauses.*.reference`) les articles des CGU :
   `security:check-config` échoue en production tant qu'une référence manque.

| Clé `terms_clauses` | Article du projet de CGU |
|---|---|
| `publication_rules` (et `pre_publication_clause`) | Article 8 |
| `prohibited_content` | Article 9 |
| `accurate_profile` | Article 10 |
| `spam_and_solicitation` | Article 11 |
| `respectful_conduct` | Article 12 |

## Points à faire valider par le juriste

| # | Document | Point | Question |
|---|---|---|---|
| L1 | CGU art. 5.1 | Âge minimum de 18 ans | Aucun contrôle technique de l'âge : la déclaration suffit-elle ? |
| L2 | CGU art. 4, 7, 14 | Qualification de place de marché (C. consom. L111-7, D111-6 à D111-8 ; voir `marketplace-rules.md` J1, J2, J4) | Les informations des articles 4 et 7 suffisent-elles comme rubrique d'information ? Faut-il une page dédiée ? |
| L3 | CGU art. 6.3, 7.3 | Traitement différencié Premium et accès anticipé (règlement P2B 2019/1150 art. 3, 5 et 7 ; décision 81 ; `marketplace-rules.md` J15) | Rédaction suffisante pour les prestataires professionnels ? |
| L4 | CGU art. 13 | DSA art. 14, 16, 17, 18, 20 ; mention des organes de règlement extrajudiciaire (art. 21) | Faut-il les mentionner (non obligatoire pour une petite entreprise) ? |
| L5 | CGU art. 20.2 | Suspension et résiliation de compte | Non outillées en V1 (lot 8, décision 28) : garder la clause ? Préavis et motivation suffisants ? |
| L6 | CGU art. 19.3 | Limitation de responsabilité | Validité envers les consommateurs et les professionnels |
| L7 | CGU art. 22 ; CGV art. 13 | Médiateur de la consommation (L612-1) | Choix du médiateur, adhésion à faire avant le lancement |
| L8 | CGU art. 14.2 | Rétractation sur les devis acceptés en ligne | Rôle de la Plateforme dans la fonction de rétractation (ordonnance n° 2026-2, voir `billing-rules.md` § 8) |
| L9 | Confidentialité § 3 | Bases légales proposées (modération : 6.1.c ; preuve des devis : 6.1.f) | À confirmer, en particulier pour T13 du registre |
| L10 | Confidentialité § 3.3 | Preuve de l'acceptation des CGU supprimée avec le compte | Faut-il la conserver plus longtemps (prescription) ? |
| L11 | Confidentialité § 3.1 | Aucune purge des comptes inactifs | La CNIL recommande une durée : en prévoir une (développement à planifier) ? |
| L12 | Confidentialité § 4.3, 5 ; registre | Sous-traitants, localisation, garanties de transfert, DPA | À compléter contrat par contrat |
| L13 | Confidentialité § 7 | Portabilité | Export complet prévu au lot 11 ; d'ici là, sur demande par email. Suffisant ? |
| L14 | Cookies | Exemption de consentement pour tous les traceurs listés | Confirmer ; durée du cookie « Se souvenir de moi » à fixer |
| L15 | CGV | Ensemble des points de `billing-rules.md` § 10 (L215-1, L215-1-1, L221-25, remboursements, empreinte de carte, TVA OSS) | — |
| L16 | Registre | AIPD (copilote, signalements) ; registre des violations | À réaliser |
