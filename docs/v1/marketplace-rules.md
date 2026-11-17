# Marketplace : règles, textes et points à faire valider

Lot 3 de la V1 (décisions 36 à 50 de `docs/v1/DECISIONS.md`). Ce document décrit ce que fait la
plateforme pour les offres, les devis, le comparateur et l'accès anticipé, les textes affichés
aux utilisateurs, et la liste des points à faire valider par le juriste et par
l'expert-comptable (section 7, tableau unique à leur transmettre tel quel).

**Statut : brouillon technique, à faire valider.** Les textes cités sont ceux du code ; ils
peuvent changer après validation.

## 1. Sources consultées

Pages consultées les 2026-11-16 et 2026-11-17. L'outil de lecture ne restitue que des résumés et
des citations courtes : chaque formulation exacte est à relire sur la page.

| Sujet | Source |
|---|---|
| Devis obligatoire selon le secteur | Fiche F31144 : https://entreprendre.service-public.gouv.fr/vosdroits/F31144 |
| Mentions du devis (bâtiment, équipement de la maison) | Arrêté du 24 janvier 2017, art. 4 : https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000033959893 |
| Information précontractuelle | C. consom. L111-1, L111-2, R111-1, R111-2 : https://www.legifrance.gouv.fr/codes/section_lc/LEGITEXT000006069565/LEGISCTA000032807114/ |
| Médiateur de la consommation | L612-1, L616-1, R616-1 : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032808378 |
| Contrat à distance, rétractation | L221-5, L221-18, L221-21, L221-25, L221-28 : https://www.legifrance.gouv.fr/codes/section_lc/LEGITEXT000006069565/LEGISCTA000032221319/ |
| Place de marché en ligne | L111-7 : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000033219601/ ; article liminaire 14° : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000049464063 ; décret n° 2024-753 (D111-6 à D111-8) : https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000049894761 |
| Règlement P2B (UE) 2019/1150 | https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:32019R1150 |
| DSA (UE) 2022/2065, art. 19, 29 à 32 | https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:32022R2065 |
| Petite entreprise | Recommandation 2003/361/CE : https://eur-lex.europa.eu/legal-content/FR/TXT/HTML/?uri=CELEX:32003H0361 |
| Devis entre professionnels, facture | C. com. L441-1, L441-9, L441-10, R123-237 ; CGI annexe II art. 242 nonies A ; fiche F31808 |
| Assurances | C. assur. L243-2 ; C. artisanat L132-1 |
| Taux de TVA des travaux dans les logements | https://www.impots.gouv.fr/professionnel/questions/quel-taux-de-tva-appliquer-pour-les-travaux-realises-dans-les-logements |
| Franchise en base, autoliquidation | BOFiP BOI-TVA-DECLA-40-10-20 (01/07/2026) ; BOI-RES-TVA-000269 (09/09/2026) |
| Acceptation en ligne | C. civ. 1113 à 1121, 1127-1, 1127-2, 1366, 1367, 1172 |

Non vérifié : les pages de la DGCCRF sur economie.gouv.fr (accès refusé) ; le décret
d'application de l'« espace » prévu par L111-7 pour les informations L221-5 et L221-6 ; le
décret d'application de L221-21.

## 2. Ce que fait la plateforme

### Identité professionnelle et qualité déclarée (décisions 37 et 38)

- Seuls des **professionnels déclarés** publient des offres et envoient des devis. Chacun
  enregistre son identité (dénomination, forme juridique, SIREN, registre, capital pour une
  société, numéro de TVA, adresse, téléphone, email, assurance, médiateur, profession
  réglementée, conditions générales) et accepte l'autocertification ci-dessous.
- Le SIREN et le numéro de TVA sont **contrôlés dans leur forme** (clé de Luhn, clé de TVA), pas
  dans leur existence (vérification par l'API publique de l'État au lot 6).
- Pas de traçabilité complète au sens de l'art. 30 du DSA (ni pièce d'identité, ni coordonnées
  bancaires) : l'art. 29 en dispense une petite entreprise.
- Sur l'offre : « Professionnel (statut déclaré) », dénomination et SIREN. L'identité complète
  n'apparaît que sur le devis.

**Autocertification** (version `2026-11-17`) : « Je déclare agir en tant que professionnel, que
les informations de mon identité professionnelle sont exactes, et je m'engage à ne proposer que
des services conformes au droit applicable. »

### Offres (décisions 39 et 40)

- Publiées sans validation préalable, signalables par tous (formulaire DSA) et masquables.
- Prix **indicatif saisi TTC** par le prestataire ; le frontend affiche « Prix indicatif, seul le
  devis engage ». Une offre peut être « sur devis ».
- Ordre des résultats : date de publication décroissante, identique pour tous (aucune mise en
  avant payante).

### Devis (décisions 36, 41, 42, 46, 49, 50)

- Un devis s'envoie **après l'acceptation de la demande de mise en relation** (le nom du client
  est alors connu du prestataire).
- Mentions imprimées : identité de l'émetteur, numéro, dates d'établissement et de validité,
  client, lieu d'exécution, objet, lignes (désignation, quantité, unité, prix unitaire HT, taux,
  total HT), totaux HT, TVA par taux et TTC, mentions de TVA, date de début, durée, conditions de
  paiement, somme versée à la commande (acompte ou arrhes), devis gratuit ou payant,
  conservation des pièces remplacées, assurance, médiateur, profession réglementée, conditions
  générales, droit de rétractation (consommateur), preuve d'acceptation, empreinte du contenu.
- **Acceptation en ligne** : le client lit le devis, coche « J'accepte ce devis », coche les
  attestations demandées par certains taux, et confirme (récapitulatif puis confirmation).
  L'acceptation n'est valable que pour le contenu lu (empreinte SHA-256). Preuve : date, adresse
  IP, cases cochées.
- **Demande expresse d'exécution** (consommateur, facultative) : « Je demande que la prestation
  commence avant la fin du délai de rétractation. »
- **Rétractation en ligne** (consommateur) : 14 jours après l'acceptation, depuis la page du
  devis ; le prestataire est prévenu.
- **Mention sur chaque devis et dans les emails** : « Loopartner met en relation les parties et
  fournit l'outil de devis, mais n'est pas partie au contrat conclu entre le prestataire et le
  client. »
- **Emails** : « devis reçu » et « devis refusé » (désactivables) ; confirmation d'acceptation
  aux deux parties **avec le PDF du devis accepté en pièce jointe**, généré au moment de l'envoi,
  et rétractation (toujours envoyées). Le corps des emails ne contient ni montant ni ligne.

### Comparateur (décisions 33 et 50, Premium)

- Devis reçus pour un même projet côte à côte, dans l'ordre de réception ; chaque constat donne
  sa règle, sa raison (le calcul) et une question à poser.
- Règles : date de début, durée ou conditions de paiement absentes ; somme demandée à la
  commande ; pas de frais de déplacement pour un projet sur place ; forfait unique d'au moins
  80 % du total ; assurance non indiquée ; validité de moins de 7 jours ou dépassée ; écart d'au
  moins 30 % à la médiane (à partir de 3 devis) ; poste présent dans la majorité des autres
  devis mais absent ; unités différentes pour un même poste ; date de début la plus proche.
- **Avertissement** : « Comparaison automatique fondée sur des règles explicables : elle ne
  constitue ni un avis sur la qualité des prestataires ni un conseil. Les prestataires sont
  présentés dans l'ordre de réception de leurs devis. »

### Accès anticipé (décision 44, Premium)

- Pendant 24 heures après sa publication (ou sa republication), un projet n'est visible que des
  prestataires Premium. L'ordre des résultats reste le même pour tous. Le porteur voit la date
  de fin de cette fenêtre dans son projet.

## 3. TVA : comment les taux sont lus

- Aucun taux n'est écrit dans le code. Les taux sont des **règles du module Compliance** (type
  `vat_rate`), vérifiées par l'expert-comptable dans l'administration, avec une note de
  vérification (qui, quand, quelle source).
- Contenu d'une règle : taux en points de base, libellé, champ d'application, mention imprimée
  (franchise, autoliquidation…), texte que le client certifie (taux réduits des travaux dans les
  logements), caractère exclusif (un taux exclusif s'applique seul à tout le devis).
- Le prestataire choisit le taux de chaque ligne parmi les taux vérifiés en vigueur ; Loopartner
  ne détermine jamais le taux applicable. À l'envoi, chaque ligne garde une copie du taux (code,
  version, valeur, libellé, mention).
- Calcul : HT de chaque ligne arrondi au centime ; TVA calculée par taux sur la somme HT des
  lignes de ce taux, arrondie au centime (demi-centime vers le haut) ; TTC = HT + TVA.
- Brouillons fournis : `backend/database/data/compliance/fr-2027-vat.draft.json` (taux normal,
  travaux d'amélioration des logements, rénovation énergétique, franchise en base,
  autoliquidation de la sous-traitance du bâtiment), **sans aucune valeur** : les taux lus le
  2026-11-16 n'indiquent pas de période d'application et sont seulement cités dans les notes.
- **Tant qu'aucun taux n'est vérifié, aucun devis ne peut être rédigé ni envoyé** (réponse 409
  `vat_rates_unavailable`). Les offres fonctionnent sans taux.

## 4. Données personnelles et durées de conservation

| Données | Conservation | Ensuite |
|---|---|---|
| Identité professionnelle | tant que le compte existe | supprimée avec le compte |
| Offre | tant que le compte existe | supprimée avec le compte |
| Brouillon de devis | 12 mois sans modification | supprimé |
| Devis refusé, retiré, expiré ou remplacé | 12 mois après ce changement | supprimé |
| Devis accepté et preuve d'acceptation (date, IP, cases cochées) | tant que la conversation existe ; 12 mois après la suppression d'un des deux comptes | supprimé avec la conversation |

## 5. Ce qui n'est pas fait dans ce lot

- Factures et facturation électronique (1er septembre 2027 pour les PME) ; paiement entre les
  parties ; signature électronique qualifiée.
- Attestation d'assurance décennale en pièce jointe (il faudrait le coffre-fort du lot 6) : le
  devis indique l'assureur, la couverture et que l'attestation est remise par le prestataire.
- Prestataires établis hors de France : ils publient des offres mais n'envoient pas de devis.

## 6. Rubrique d'information de la place de marché (à rédiger)

Si Loopartner est une place de marché en ligne (point J1 ci-dessous), une rubrique accessible
sans compte doit présenter (D111-6 et D111-8, à confirmer) : critères de classement (date de
publication) ; qualité des personnes qui déposent une offre (professionnels déclarés) ; nature
du service (mise en relation, outil de devis) ; prix du service pour le consommateur (gratuit,
Premium) ; absence de paiement par la plateforme ; absence de garantie de la plateforme ;
traitement des litiges (médiateur du prestataire, signalement DSA) ; accès anticipé des
prestataires Premium aux nouveaux projets.

## 7. Points à faire valider (tableau unique)

| N° | Pour | Point | Ce que fait la plateforme aujourd'hui | Question posée |
|---|---|---|---|---|
| J1 | Juriste | Qualification de place de marché en ligne (C. consom. liminaire 14°, L111-7, D111-6, D111-8) | L'acceptation en ligne des devis permet de conclure un contrat à distance sur la plateforme | Loopartner est-elle une place de marché ? Quelles informations la rubrique (section 6) doit-elle contenir ? |
| J2 | Juriste | « Espace » pour les informations L221-5 et L221-6 (dernier alinéa de L111-7) | Identité professionnelle et devis portent les informations de L111-1, R111-1, R111-2 et L221-5 | Le décret d'application existe-t-il ? Notre devis suffit-il ? |
| J3 | Juriste | Dispense des art. 30 à 32 DSA (art. 29) | Pas de pièce d'identité ni de coordonnées bancaires ; autocertification et SIREN contrôlé dans sa forme | Statut de petite entreprise consolidé (entreprises liées ou partenaires), règle des 12 mois : la dispense s'applique-t-elle ? |
| J4 | Juriste | Affichage de la qualité de l'offreur (D111-8 II) | « Professionnel (statut déclaré) », dénomination et SIREN sur l'offre | Libellé suffisant ? Faut-il ouvrir les offres à des non-professionnels (avertissements supplémentaires) ? |
| J5 | Juriste | Texte de l'autocertification | Texte de la section 2, version `2026-11-17` | Texte à valider |
| J6 | Juriste | Prix indicatif TTC des offres | Saisi TTC, mention « prix indicatif, seul le devis engage » | Conforme aux règles d'affichage des prix envers les consommateurs ? |
| J7 | Juriste | Mentions obligatoires du devis | Liste de la section 2 | Liste complète pour chaque secteur concerné ? Champ de l'arrêté de 2017 pour un contrat conclu à distance (son art. 4 vise l'établissement et le hors établissement) ? |
| J8 | Juriste | Nom et adresse du client sur le devis | Saisis par le prestataire après l'acceptation de la demande ; le client ne les modifie pas (il refuse avec le motif « informations incorrectes ») | Procédure acceptable ? |
| J9 | Juriste | Acceptation en ligne | Récapitulatif, case « J'accepte ce devis », empreinte du contenu, preuve date et IP ; pas de signature électronique qualifiée ni de « bon pour accord » | Valeur probante suffisante (C. civ. 1127-2, 1366, 1367) ? |
| J10 | Juriste | Demande expresse d'exécution (L221-25) | Case facultative pour un consommateur, texte de la section 2 | Texte et moment de recueil corrects ? |
| J11 | Juriste | Rétractation en ligne (L221-21) | Fonction fournie par Loopartner, 14 jours après l'acceptation, prestataire prévenu | Qui porte l'obligation (prestataire ou plateforme) ? Décret d'application ? Point de départ du délai pour un service ? |
| J12 | Juriste | « Loopartner n'est pas partie au contrat » | Mention sur chaque devis, dans l'API et dans les emails | Texte et portée à valider ; clause correspondante des CGU |
| J13 | Juriste | Support durable | PDF du devis accepté joint à la confirmation, aux deux parties ; PDF de chaque devis téléchargeable gratuitement | Suffisant pour la confirmation du contrat sur support durable ? |
| J14 | Juriste | Acompte et arrhes | Le prestataire choisit la nature ; le comparateur rappelle la différence | Texte du rappel correct ? |
| J15 | Juriste | Accès anticipé Premium (P2B art. 3, 5 §3, 7 ; D111-6) | 24 heures réservées aux Premium, ordre identique pour tous, date de fin visible du porteur | Clause des CGU et information du porteur de projet suffisantes ? |
| J16 | Juriste | Avertissement du comparateur | Texte de la section 2 | Texte à valider |
| J17 | Juriste | Durées de conservation | Tableau de la section 4 | Durées acceptables (preuve d'un contrat dont Loopartner n'est pas partie) ? À reporter dans la politique de confidentialité |
| J18 | Juriste | Assurance décennale | Le devis indique l'assureur, la couverture, et que l'attestation est remise par le prestataire | Suffisant tant que la pièce jointe n'est pas possible (L243-2) ? |
| E1 | Expert-comptable | Liste des taux | Cinq codes de la section 3 | Liste complète pour 2027 ? Autres taux ou exonérations à prévoir (formation, etc.) ? |
| E2 | Expert-comptable | Valeur de chaque taux 2027 | Aucune valeur dans les brouillons | Valeurs et sources à saisir, puis vérification dans l'administration |
| E3 | Expert-comptable | Champ d'application de chaque taux | À rédiger | Texte affiché au prestataire |
| E4 | Expert-comptable | Mentions de TVA | Franchise : « TVA non applicable, article 293 B du CGI » ou « article L. 223-3 du CIBS » ; autoliquidation : « Autoliquidation » | Formulation 2027 après la recodification dans le CIBS |
| E5 | Expert-comptable | Certification du client pour les taux réduits des travaux dans les logements | À rédiger | Texte exact de la mention depuis le 1er mars 2025 |
| E6 | Expert-comptable | Méthode d'arrondi | HT par ligne, TVA par taux sur la somme HT, au centime | Méthode acceptée ? |
| E7 | Expert-comptable | Acompte et TVA | Montant TTC saisi, sans calcul de TVA propre | À traiter différemment ? |
| E8 | Expert-comptable | Devis envers un professionnel | Totaux HT, TVA et TTC | Affichage suffisant ? Mentions L441-9 (pénalités, indemnité forfaitaire) à ajouter au devis ? |
| E9 | Expert-comptable | Clients établis dans un autre pays de l'UE | Pas de règle propre en V1 | Faut-il une règle (autoliquidation intracommunautaire) ou exclure ces clients ? |
| E10 | Expert-comptable | Franchise et autres taux | Un taux exclusif s'applique seul à tout le devis | Correct pour la franchise et l'autoliquidation ? |
