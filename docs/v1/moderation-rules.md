# Signalements et modération : règles et textes destinés aux utilisateurs

**Brouillon à faire valider par le juriste** (lot 2, décisions 21 à 31 de `DECISIONS.md`). Aucun
texte de ce document n'a de valeur juridique tant qu'il n'a pas été relu. Les textes des emails
sont dans le code (`backend/app/Modules/Moderation/Enums/ModerationMail.php`,
`Events/Enums/EventNotice.php`) et reprennent ce brouillon : toute correction du juriste doit y
être reportée.

Sources lues le 2026-11-16 :

- Règlement (UE) 2022/2065 (DSA), texte officiel en français (CELEX 32022R2065), lu sur le serveur
  de l'Office des publications de l'UE (`https://publications.europa.eu/resource/celex/32022R2065`,
  même texte qu'EUR-Lex : `https://eur-lex.europa.eu/legal-content/FR/TXT/?uri=CELEX:32022R2065`).
- Arcom, « Règlement sur les services numériques ou DSA : obligations et services concernés »
  (page mise à jour le 6 février 2026) et « enregistrement d'une plateforme en ligne sur la base de
  données de transparence (art. 24.5) ».
- Commission européenne, « Digital Services Act: Questions and Answers » (mise à jour du
  19 décembre 2025).
- Base de données de transparence DSA, documentation de l'API (catégories des exposés des motifs).
- Ministère de l'Intérieur : portail officiel de signalement PHAROS
  (`https://internet-signalement.gouv.fr/`, fiche « Ma Sécurité »).

## 1. Ce que le DSA impose à Loopartner (rappel du plan validé)

| Obligation | Article | Pour Loopartner (petite entreprise) |
|---|---|---|
| Point de contact pour les autorités | 11 | Oui : `MODERATION_AUTHORITIES_EMAIL` (legal@loopartner.com), français et anglais |
| Point de contact pour les utilisateurs | 12 | Oui : `MODERATION_CONTACT_EMAIL` (contact@loopartner.com), français et anglais |
| CGU décrivant la modération et le traitement des réclamations | 14 | Oui : clauses à rédiger (section 4) |
| Rapport de transparence | 15 | Non (art. 15 §2) |
| Mécanisme de notification et d'action | 16 | Oui |
| Exposé des motifs | 17 | Oui |
| Signalement des infractions menaçant la vie ou la sécurité | 18 | Oui (section 6) |
| Recours interne, organes extrajudiciaires, signaleurs de confiance, abus, transparence | 20 à 28 | Non (art. 19), sauf l'art. 24 §3 ; recours interne appliqué volontairement (décision 21) |
| Traçabilité des professionnels sur une place de marché | 30 à 32 | Non (art. 29) ; à revoir au lot 3 |

**À faire valider par le juriste** : la qualification de plateforme en ligne (dont les projets,
visibles des seuls prestataires inscrits), le statut de petite entreprise (entreprises liées ou
partenaires de la recommandation 2003/361/CE) et la période de 12 mois après sa perte (art. 19
§1), le fait qu'un refus avant publication soit une « restriction » au sens de l'art. 17, les
obligations françaises en plus du DSA (loi SREN, LCEN modifiée), l'art. 24 §3 (nombre moyen de
destinataires actifs à fournir sur demande, sans méthode de calcul en place aujourd'hui).

## 2. Formulaire de signalement (art. 16)

Ouvert à tous, avec ou sans compte : `POST /api/v1/moderation/notices`.

- **Contenu signalé** : type et identifiant du contenu (le frontend les remplit depuis la page),
  précision facultative sur l'emplacement (paragraphe, image…).
- **Catégorie** (décision 22) :

| Clé | Libellé | Priorité | Nom et email |
|---|---|---|---|
| `scam_fraud` | Arnaque ou fraude | normale | obligatoires |
| `hate_harassment` | Contenu haineux ou harcèlement | normale | obligatoires |
| `privacy` | Atteinte à la vie privée ou aux données personnelles | normale | obligatoires |
| `intellectual_property` | Contrefaçon ou atteinte à la propriété intellectuelle | normale | obligatoires |
| `illegal_activity` | Activité ou profession illégale | normale | obligatoires |
| `child_sexual_abuse` | Abus sexuels sur mineurs | **urgente** | **facultatifs** (art. 16 §2 c) |
| `violence_terrorism` | Incitation à la violence ou terrorisme | **urgente** | obligatoires |
| `misleading_commercial` | Pratique commerciale trompeuse | normale | obligatoires |
| `terms` | Contraire aux CGU (spam, hors sujet, faux profil) | normale | obligatoires |
| `other` | Autre | normale | obligatoires |

- **Explication** (20 à 5000 caractères) : pourquoi le contenu est illicite ou contraire aux CGU.
- **Déclaration de bonne foi**, texte proposé : « Je déclare de bonne foi que les informations et
  les allégations de ce signalement sont exactes et complètes. »
- **Avec un compte** : le nom et l'email sont ceux du compte.
- **Texte d'information proposé** sous le formulaire : « Votre signalement est examiné par un
  membre de l'équipe, sans outil automatisé. Vous recevez un accusé de réception, puis notre
  décision et la façon de la contester. Votre nom et votre email ne sont jamais communiqués à
  l'auteur du contenu. Ils sont effacés 12 mois après la clôture de votre signalement. »

### Accusé de réception (art. 16 §4)

Sans compte, par email (`notice_received`) : « Nous avons bien reçu votre signalement concernant
un contenu de type « … ». Nous l'examinons et vous informerons de notre décision. » avec la
référence et le lien de suivi, qui est personnel. Avec un compte, la réponse du formulaire et la
page « Mes signalements » en tiennent lieu.

### Objectifs de traitement (décision 31)

- Signalement **urgent** (abus sexuels sur mineurs, violence ou terrorisme) : **24 heures** ;
  alerte immédiate par email aux admins (double authentification confirmée), sans aucun contenu ;
  en tête de la file de modération.
- Autres signalements : **7 jours**.
- Ce sont des objectifs internes (`config/moderation.php`, `processing_hours`), pas des délais
  légaux : l'hébergeur doit agir « promptement » une fois informé (art. 6 et 16 §3). La file
  d'administration affiche les signalements en retard.

## 3. Décision et exposé des motifs (art. 16 §5 et 17)

### À l'auteur du contenu (`decision_taken`)

Objet : « Décision de modération concernant votre contenu ». Contenu, dans cet ordre :

1. « Votre contenu « [titre] » ([type]) n'est plus visible sur Loopartner à la suite d'une
   décision de l'équipe de modération. »
2. Origine : un ou plusieurs signalements / une vérification de l'équipe / l'examen avant
   publication (l'identité du signalant n'est **jamais** communiquée, décision 29).
3. Fondement : « conditions générales d'utilisation, [clause] ([article]) » ou « contenu illicite
   au regard de [référence juridique] ».
4. Faits et motifs (rédigés par l'admin, 30 à 3000 caractères).
5. Ce que l'auteur peut corriger, quand c'est possible (décision 23) ; après correction, il
   demande le réexamen par la voie du recours.
6. « Cette décision a été prise par un membre de l'équipe de modération, sans outil automatisé. »
7. Portée : partout où Loopartner est accessible, jusqu'à la levée de la décision.
8. Recours : « Vous pouvez contester cette décision gratuitement jusqu'au [date], depuis la page
   de la décision. » et « Vous pouvez aussi saisir la juridiction compétente. »

**À faire valider par le juriste** : faut-il aussi mentionner les organes de règlement
extrajudiciaire certifiés (art. 21, non obligatoire pour une petite entreprise) ?

### Au signalant (`notice_decided`)

La décision (contenu masqué ou aucune mesure), son fondement, la possibilité de contester une
absence de mesure jusqu'à la date indiquée, et la voie judiciaire. **Ni les faits, ni le titre du
contenu.**

### Modération avant publication (Events, Blog)

Les refus, annulations et dépublications existants produisent le même exposé des motifs,
consultable et contestable ; leurs emails ajoutent (décision 26) : « Exposé des motifs de cette
décision et possibilité de la contester gratuitement : [lien] ». La clause citée est
« Règles de publication des contenus » (`pre_publication_clause`).

## 4. Clauses des CGU à rédiger (art. 14)

`config/moderation.php` (`terms_clauses`) cite les clauses ci-dessous ; `reference` reçoit le
numéro de l'article une fois les CGU rédigées (`security:check-config` échoue en production tant
qu'il manque) :

| Clé | Intitulé | À couvrir |
|---|---|---|
| `publication_rules` | Règles de publication des contenus | validation avant publication des événements et articles, révisions |
| `prohibited_content` | Contenus interdits | liste des contenus interdits, liens |
| `accurate_profile` | Exactitude du profil et identité | faux profils, usurpation |
| `spam_and_solicitation` | Spam et sollicitations abusives | démarchage, sollicitations hors plateforme |
| `respectful_conduct` | Respect des autres utilisateurs | harcèlement, propos injurieux |

Les CGU doivent aussi décrire (art. 14 §1) : le signalement, l'examen humain (aucun outil
automatisé aujourd'hui), les mesures possibles (masquage réversible, effets sur un profil
prestataire masqué : décision 23, sur un événement masqué : décision 24), l'exposé des motifs et
le règlement du recours interne (section 5), les durées de conservation (section 7).

## 5. Recours interne (aligné sur l'art. 20, décision 21)

- Gratuit, en ligne, **pendant 6 mois** après la décision (`appeal_window_months`).
- Auteur du contenu : contre une restriction en vigueur ; c'est aussi la voie du réexamen après
  une correction. Accepté : le contenu redevient visible (pour une décision d'Events ou du Blog :
  l'auteur soumet de nouveau son contenu).
- Signalant : contre une décision qui n'a pris aucune mesure. Accepté : son signalement est
  examiné à nouveau.
- Un recours en attente à la fois, **trois au plus** par personne et par décision.
- Examiné par un admin, jamais de manière automatisée ; décision motivée envoyée par email
  (`appeal_decided`), avec la voie judiciaire.
- Contrôle à quatre yeux (`MODERATION_REQUIRE_DISTINCT_APPEAL_REVIEWER`) à activer dès que
  l'équipe compte deux admins.

## 6. Procédure de signalement aux autorités (art. 18) — à faire valider par le juriste

Quand un contenu fait soupçonner une infraction pénale qui menace la vie ou la sécurité d'une ou
plusieurs personnes (déjà commise, en cours ou susceptible de l'être), l'admin informe
**promptement** les autorités. Rien n'est envoyé automatiquement.

1. **Danger immédiat pour une personne** : appeler les secours (17 ou 112) avant toute autre
   démarche. *À faire valider.*
2. **Contenu public illicite** (apologie ou provocation au terrorisme, abus sexuels sur mineurs,
   menaces…) : signalement sur PHAROS, portail officiel du ministère de l'Intérieur
   (`https://internet-signalement.gouv.fr/`), dont les enquêteurs qualifient les faits et les
   orientent vers le service compétent. Selon le portail, les affaires privées (insultes,
   harcèlement entre personnes) relèvent du commissariat ou de la brigade de gendarmerie.
3. **État membre concerné inconnu** : autorités de l'État d'établissement de Loopartner, ou
   Europol (art. 18 §2). *À faire valider.*
4. **Avant de transmettre** : ne copier ni télécharger aucune image d'abus sexuels sur mineurs ;
   transmettre l'URL et la référence du signalement. La copie conservée par Loopartner est
   uniquement textuelle et l'administration ne réaffiche pas les images de ces contenus.
5. **Masquer le contenu** par une décision motivée (fondement « contenu illicite » et référence
   juridique), sauf instruction contraire des autorités. *À faire valider : faut-il conserver le
   contenu à la demande des enquêteurs, et comment ?*
6. **Tracer la transmission** : `POST /api/v1/admin/moderation/notices/{uuid}/referral` avec
   l'autorité (`pharos`, `police`, `gendarmerie`, `europol`, `other`) ; une seule fois par
   signalement, inscrite au journal d'audit.

## 7. Données personnelles et durées de conservation (décision 27)

| Données | Conservation | Ensuite |
|---|---|---|
| Identité et email du signalant sans compte, explication, précision sur l'emplacement, copie du contenu signalé, jeton de suivi | 12 mois après la clôture du signalement (ou sa réception s'il est encore ouvert) | effacés |
| Exposés des motifs, recours, signalements traités | 24 mois après la fin du délai de recours ou la levée (la plus tardive) | supprimés ; une restriction en vigueur garde son exposé des motifs |
| Signalements classés sans décision | 24 mois après leur clôture | supprimés |
| Journal d'audit des décisions | 24 mois | supprimé |

À reprendre dans la politique de confidentialité (base légale proposée : obligation légale, art.
6.1.c RGPD, pour le traitement des signalements et l'exposé des motifs ; intérêt légitime, art.
6.1.f, pour la conservation et le recours volontaire). **À faire valider par le juriste.**

## 8. Avis (lot 6)

Les avis en ligne relèvent aussi du Code de la consommation (article L111-7-2 et ses articles
réglementaires, anciennement D111-16 et D111-17, renumérotés par le décret n° 2024-753) :
information sur l'existence d'une procédure de contrôle, la date de publication, les critères de
classement, les contreparties et la durée de conservation. **À faire valider par le juriste au
plan du lot 6.**
