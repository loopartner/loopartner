# Rendez-vous et agenda : règles, textes et points à faire valider

Lot 4 de la V1 (décisions 52 à 59 de `docs/v1/DECISIONS.md`). Ce document décrit ce que fait la
plateforme pour les rendez-vous, l'agenda unifié et l'abonnement au calendrier, les textes
affichés aux utilisateurs, et la liste des points à faire valider par le juriste (section 7,
tableau unique à lui transmettre tel quel).

**Statut : brouillon technique, à faire valider.** Les textes cités sont ceux du code ; ils
peuvent changer après validation.

## 1. Sources consultées

Toutes les pages ont été consultées le **2026-11-17**. L'outil de lecture ne restitue que des
résumés et des citations courtes : chaque formulation exacte est à relire sur la page.
**EUR-Lex n'a pas pu être lu** pendant le travail (page vide) : le RGPD a été lu dans le texte
reproduit par la CNIL, et les directives 2000/84/CE et 2002/58/CE n'ont pas été lues.

| Sujet | Constat | Source |
|---|---|---|
| RGPD art. 5.1.c, 5.1.e, 6.1.b, 9 | Minimisation ; conservation limitée à la finalité ; exécution du contrat ; interdiction de principe des données sensibles (santé, opinions…) | https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre2 |
| RGPD art. 12.3, 13.2.a, 15.3, 17, 20 | Réponse dans un mois ; durée de conservation annoncée ; copie électronique d'usage courant ; effacement ; format structuré, couramment utilisé et lisible par machine | https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3 |
| RGPD art. 25, 32 | Protection dès la conception et par défaut ; chiffrement | https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre4 |
| Durées de conservation | Base active, archivage intermédiaire (notamment pour un contentieux, accès restreint), archivage définitif | https://www.cnil.fr/fr/les-durees-de-conservation-des-donnees |
| Référentiel « gestion des activités commerciales » (non contraignant) | Preuve conservée dans la limite de la prescription applicable ; prospection : 3 ans après la fin de la relation ; compte inactif : 2 ans « proportionné » ; « éviter les zones de commentaires libres ou les encadrer strictement » | https://www.cnil.fr/sites/default/files/atoms/files/referentiel_traitements-donnees-caractere-personnel_gestion-activites-commerciales.pdf (numéro de délibération non vérifié) |
| Outils de prise de rendez-vous | Aucune recommandation générale de la CNIL trouvée ; seuls des documents propres à la santé existent (non applicables, non lus) | — |
| Messages transactionnels | Messages « nécessaires à la gestion ou l'exécution d'un contrat ou d'un service demandé par la personne » ; ne reposent « généralement pas sur le consentement préalable » ; une part promotionnelle significative peut les requalifier en prospection | https://www.cnil.fr/fr/communication-electronique-quelles-regles |
| CPCE L34-5 | Consentement préalable à la prospection par email. La page de Légifrance mentionne une **décision n° 2026-1210 QPC du 25 juin 2026** (abrogation de certaines dispositions reportée au 31 octobre 2027), **non vérifiée** sur le site du Conseil constitutionnel | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000042155961 |
| C. consom. L111-7 | Le I est abrogé depuis le 17 février 2024 ; le II vise le « fournisseur de place de marché en ligne ou de comparateur en ligne » (information loyale, claire et transparente) | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000049571125 |
| C. consom. D111-7, D111-8 | Décret n° 2024-753 : paramètres de classement, qualité de l'offreur, fonctionnement de la mise en relation | https://www.legifrance.gouv.fr/codes/section_lc/LEGITEXT000006069565/LEGISCTA000032807112/ |
| Prescription | C. civ. 2224 : cinq ans ; C. consom. L218-2 : deux ans pour l'action des professionnels contre les consommateurs | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000019017112 ; https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032226897 |
| Heure légale | Décret n° 2017-292 : UTC + 1 h, UTC + 2 h pendant l'heure d'été, du dernier dimanche de mars au dernier dimanche d'octobre à 1 h UTC (version initiale lue) | https://www.legifrance.gouv.fr/loda/id/JORFTEXT000034154759 |
| Fin du changement d'heure | Voté par le Parlement européen en 2019, le texte « n'est plus à l'ordre du jour » | https://www.service-public.gouv.fr/particuliers/actualites/A15263 |
| Droit à la portabilité | Formats JSON, CSV, XML… (PDF déconseillé) ; un mois, trois si complexe ; téléchargement en libre-service recommandé | https://www.cnil.fr/fr/le-droit-la-portabilite-en-questions |
| Droit d'accès | Un mois, trois si complexe ; copie sous une forme électronique d'usage courant | https://www.cnil.fr/fr/repondre-une-demande-de-droit-dacces |

**Non vérifié** : directives 2000/84/CE (heure d'été) et 2002/58/CE art. 13 (EUR-Lex
illisible) ; décision QPC n° 2026-1210 ; page de la DGCCRF sur les frais d'annulation des
professionnels de santé (accès refusé, et sans rapport direct avec notre cas).

## 2. Ce que fait la plateforme

### Qui réserve chez qui (décision 52)

- Seuls les **prestataires** (email vérifié, profil publié avec une catégorie) publient des
  disponibilités : plages hebdomadaires en heure locale, exceptions (fermetures, ouvertures
  ponctuelles) et types de rendez-vous (titre, description, durée, mode de lieu).
- **Dans une mise en relation acceptée** (tous les prestataires, gratuit) : le porteur de projet
  réserve un créneau (confirmé tout de suite si le prestataire a choisi la confirmation
  automatique pour ce type, sinon à confirmer) ; ou le prestataire propose un créneau que le
  client confirme.
- **Page de réservation publique** (prestataire Premium) : un particulier ou un entrepreneur
  réserve un type public en écrivant un motif ; une demande de contact est envoyée en même
  temps ; le prestataire confirme (la conversation s'ouvre) ou refuse.
- Aucun rendez-vous n'est payant ; Loopartner ne facture ni l'annulation ni l'absence.

### Ce que chaque partie voit (décision 53)

| | Le client voit | Le prestataire voit |
|---|---|---|
| Avant confirmation (page publique) | Profil public du prestataire, type, durée, mode de lieu | Type de compte, pays et motif du client, **pas son nom** |
| Après confirmation | Adresse du prestataire ou lien de visio, messages du prestataire | Nom du client, sa note, l'adresse ou le téléphone qu'il a donnés si le mode l'exige |
| Jamais | Email ou téléphone personnel du prestataire | Email du client |

### Annulation, report, expiration (décision 55)

- Annulation par chaque partie jusqu'au début, sans frais ; motif en liste fermée et message
  facultatif.
- Report : proposé par une partie, accepté ou refusé par l'autre ; trois au plus.
- Demande sans réponse : expirée après 48 heures, ou plus tôt au délai de prévenance du
  prestataire.

### Emails (décision 56)

Titre du type de rendez-vous et date dans le fuseau du destinataire, et un lien vers son
espace ; **jamais** de note, d'adresse, de téléphone, de lien de visio ni de nom. Un fichier
`.ics` (titre et dates seulement) est joint à la confirmation.

- Catégorie **obligatoire** `appointments.bookings` : demande reçue, proposition reçue,
  confirmation, refus, annulation, report proposé, accepté ou refusé, expiration.
- Catégorie **facultative** `appointments.reminders` : rappels avant le rendez-vous (24 h ; le
  prestataire Premium peut choisir jusqu'à deux délais parmi 2 h, 24 h et 48 h).

### Agenda unifié et abonnement

- `GET /me/agenda` : rendez-vous, événements auxquels l'utilisateur est inscrit ou qu'il
  organise, échéances du suivi administratif.
- Abonnement unique (`/agenda/{token}.ics`) : **titres et dates seulement**, ni lieu, ni lien,
  ni note, ni nom ; URL secrète montrée une fois, révocable. L'abonnement du suivi administratif
  reste disponible.

## 3. Textes affichés

**Avertissement près de la note libre** (décision 58) :

> fr : « Facultatif, 500 caractères au plus. N'indiquez pas d'information de santé ni d'autre
> donnée sensible : le prestataire lira ce message. »
> en : "Optional, 500 characters at most. Do not include health information or other sensitive
> data: the provider will read this message."

**Information avant une réservation sur la page publique** :

> fr : « Votre motif et votre type de compte sont transmis au prestataire avec une demande de
> mise en relation. Votre nom ne lui est communiqué que s'il confirme le rendez-vous. »
> en : "Your message and account type are sent to the provider with a connection request. Your
> name is only shared if they confirm the appointment."

**Abonnement à l'agenda** :

> fr : « Gardez cette adresse privée : toute personne qui la connaît peut lire les titres et
> les dates de votre agenda. Régénérez-la si elle a été partagée. »

## 4. Données personnelles et durées de conservation (décision 57)

| Données | Conservation | Ensuite |
|---|---|---|
| Lieu d'un rendez-vous (adresse, téléphone, lien de visio) et notes libres | 3 mois après la date du rendez-vous ou sa clôture | effacés (le rendez-vous reste) |
| Rendez-vous refusé, annulé ou expiré | 12 mois après ce changement | supprimé |
| Rendez-vous passé (dates, type, statut) | 24 mois après sa date, ou avant avec sa conversation | supprimé |
| Trace d'un rappel envoyé, exception passée | 3 mois | supprimées |
| Réglages, types, plages, abonnement à l'agenda | tant que le compte existe | supprimés avec le compte |

- **Chiffrement en base** : lieu, notes et détails de lieu des types.
- **Base légale proposée** : exécution du service demandé (art. 6.1.b) ; intérêt légitime
  (art. 6.1.f) pour la conservation après la date et après la suppression d'un compte.
- **Suppression de compte** : rendez-vous à venir annulés (l'autre partie est prévenue
  sobrement), lieu fourni par la personne effacé, rendez-vous passés détachés du compte ;
  réglages et abonnement supprimés.
- **Export** : l'export général (lot 11) reprendra `AppointmentsPersonalData`.

## 5. Ce qui n'est pas fait dans ce lot

Paiement des rendez-vous, frais d'annulation, marquage « non honoré », synchronisation avec un
agenda externe dans les deux sens, visioconférence intégrée, export RGPD général (lot 11).

## 6. Clauses à prévoir dans les CGU et la politique de confidentialité

- CGU : page de réservation réservée au Premium ; Loopartner n'est pas partie à la prestation ;
  annulation libre et sans frais sur la plateforme.
- Politique de confidentialité : tableau de la section 4, rappels par email, abonnement au
  calendrier.

## 7. Points à faire valider (tableau unique)

| N° | Pour | Point | Ce que fait la plateforme aujourd'hui | Question posée |
|---|---|---|---|---|
| R1 | Juriste | Emails de rappel (CPCE L34-5, CNIL) | Rappels envoyés sans consentement préalable, catégorie facultative désactivable, sans contenu promotionnel | Un rappel de rendez-vous est-il bien un message de service (transactionnel) ? |
| R2 | Juriste | Décision n° 2026-1210 QPC (L34-5) | Non prise en compte | Effets sur nos emails (aucune prospection n'est envoyée) ? |
| R3 | Juriste | Données sensibles dans une note libre (RGPD art. 9) | Note facultative de 500 caractères, chiffrée, avertissement près du champ, effacée après 3 mois | Faut-il une base de l'art. 9.2, ou la minimisation et l'avertissement suffisent-ils ? Texte de l'avertissement à valider |
| R4 | Juriste | Place de marché en ligne (L111-7 II, D111-8) | La réservation met en relation un client et un professionnel ; aucun classement payant | Loopartner est-elle une place de marché pour les rendez-vous ? Informations à donner ? |
| R5 | Juriste | Frais d'annulation et absences | Aucun frais, aucun marquage, annulation libre jusqu'au début | Aucune obligation propre à prévoir tant que la plateforme ne facture rien ? |
| R6 | Juriste | Information précontractuelle d'un service gratuit (L111-1) | Information avant la réservation (section 3) | Suffisante ? |
| R7 | Juriste | Durées de conservation (section 4) | 3, 12 et 24 mois | Acceptables au regard de la preuve (prescription de 2 ou 5 ans) pour une plateforme qui n'est pas partie à la prestation ? À reporter dans la politique de confidentialité |
| R8 | Juriste | Révélation du nom à la confirmation | Nom du client montré au prestataire seulement après confirmation | Information du client (section 3) suffisante ? |
| R9 | Juriste | Page de réservation Premium | Réservée aux prestataires Premium, ordre et visibilité des profils inchangés | Clause des CGU (traitement différencié) ? |
| R10 | Juriste | Directives 2000/84/CE et 2002/58/CE | Non lues (EUR-Lex inaccessible pendant le travail) | Relecture des articles 2 et 3 (heure d'été) et 13 (communications non sollicitées) |
| R11 | Juriste | Abonnement au calendrier | URL secrète, titres et dates seulement, révocable | Information suffisante (section 3) ? |
