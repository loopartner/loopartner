# Registre des activités de traitement (article 30 du RGPD)

> **Document interne, projet du 2026-11-22, à faire valider par le juriste.** Il n'est pas publié
> sur le site. Il doit être tenu à jour à chaque nouveau module ou changement de sous-traitant, et
> présenté à la CNIL sur demande. L'exception de l'article 30 §5 (moins de 250 salariés) ne
> s'applique pas : les traitements de la Plateforme sont réguliers, et certains portent sur des
> données de signalement d'infractions.

## Responsable du traitement

| Rubrique | Valeur |
|---|---|
| Organisme | [À COMPLÉTER : dénomination, forme, SIREN, adresse] |
| Représentant légal | [À COMPLÉTER] |
| Contact données personnelles | [À COMPLÉTER : adresse email dédiée] |
| Délégué à la protection des données | [À COMPLÉTER : désigné ou non ; s'il ne l'est pas, motif] |

## Sous-traitants (article 28)

| Sous-traitant | Traitement confié | Données | Localisation, garanties de transfert | Contrat (DPA) |
|---|---|---|---|---|
| Hostinger [À COMPLÉTER : entité] | Hébergement de l'API, de la base, des files d'attente, des journaux | Toutes les données de la base | [À COMPLÉTER] | [À COMPLÉTER : date de signature] |
| Vercel Inc. | Hébergement du site (Next.js) | Adresse IP, journaux techniques | [À COMPLÉTER : DPF ou CCT] | [À COMPLÉTER] |
| Cloudflare, Inc. (R2) | Stockage et diffusion des images | Images publiques (réencodées, sans métadonnées) | [À COMPLÉTER] | [À COMPLÉTER] |
| Resend | Envoi des emails | Adresse email, nom, contenu des emails | [À COMPLÉTER : DPF ou CCT] | [À COMPLÉTER] |
| Mistral AI (si `AI_DRIVER=mistral`) | Copilote | Contenu expurgé décrit dans `docs/v1/workspaces-rules.md` § 3 | UE (endpoint `api.eu.mistral.ai`) ; ZDR à demander | [À COMPLÉTER] |
| Stripe (lot 10) | Paiement, factures | Identité, email, données de paiement (chez Stripe) | [À COMPLÉTER] | [À COMPLÉTER] |

Have I Been Pwned ne reçoit qu'un préfixe de cinq caractères de l'empreinte SHA-1 du mot de passe
(k-anonymat) : aucune donnée personnelle, pas de contrat de sous-traitance nécessaire
(à confirmer par le juriste).

## Traitements

Durées : valeurs de `backend/config/security.php` (`retention`) au 2026-11-22. Personnes
concernées : visiteurs (V), membres (M : particuliers, prestataires, entrepreneurs), équipe (É),
signalants sans compte (S), clients cités dans un devis (C).

| # | Traitement | Finalité | Base légale | Personnes | Données | Destinataires | Durée | Mesures particulières |
|---|---|---|---|---|---|---|---|---|
| T1 | Comptes | Créer et gérer le compte, langue, fuseau, préférences | Contrat (6.1.b) | M, É | Nom, email, empreinte du mot de passe, type de compte, pays, langue, fuseau, avatar, préférences de notification, onboarding | Équipe (support) | Durée du compte | Empreinte bcrypt, email vérifié, aucune révélation de l'existence d'un compte |
| T2 | Authentification et sécurité | Sécuriser l'accès, prévenir les abus et la collecte massive | Intérêt légitime (6.1.f), art. 32 | V, M, É | IP, navigateur, sessions, secret 2FA (chiffré), journal `security` (sans email en clair, empreinte seulement) | Équipe technique | Sessions : 120 min ; journal : 30 jours | Limiteurs de débit, 2FA obligatoire pour l'équipe, contrôle HIBP par k-anonymat |
| T3 | Acceptation des CGU et de la politique | Preuve du consentement contractuel | Intérêt légitime, contrat | M | Type, version, date, IP | — | Durée du compte | Versions immuables, empreinte SHA-256 du texte |
| T4 | Profils de prestataires et identité professionnelle | Présenter le prestataire, établir des devis conformes, informer sur la qualité du vendeur | Contrat ; C. consom. L111-7 | M (prestataires) | Profil public ; identité professionnelle chiffrée ; autocertification | Public (profil, qualité déclarée, dénomination, SIREN) | Durée du compte | Chiffrement, contrôle de forme SIREN et TVA |
| T5 | Projets | Publier et faire connaître un projet | Contrat | M (porteurs) | Titre, description, catégorie, budget, pays, ville, date de début | Public (vitrine sans identité ni ville) ; prestataires Premium (détail) | Jusqu'à suppression | Consultations journalisées, alerte de collecte au-delà de 300 par jour |
| T6 | Mise en relation et messagerie | Échanges entre membres | Contrat ; intérêt légitime (blocages) | M | Demandes, messages, lectures, blocages | L'interlocuteur | Demandes closes : 12 mois ; conversation orpheline : 12 mois | Nom révélé après acceptation, aucun contenu dans les emails |
| T7 | Offres, devis, comparateur | Établir, accepter et prouver un devis | Contrat ; intérêt légitime (preuve) | M, C | Lignes, montants, bloc client, identité de l'émetteur copiée, preuve d'acceptation (date, IP, cases) | Les deux parties | Brouillons et devis clos : 12 mois ; acceptés : durée de la conversation | Empreinte SHA-256 du contenu, aucun montant dans les emails ni les journaux |
| T8 | Rendez-vous | Planifier des rendez-vous | Contrat | M | Disponibilités, rendez-vous, lieu, téléphone, lien de visio (chiffrés), notes | Les deux parties | Lieux et notes : 3 mois ; clos : 12 mois ; passés : 24 mois | Lieu révélé après confirmation, rappels sans détail |
| T9 | Événements | Agenda public et inscriptions | Contrat | M | Événements, lien de connexion chiffré, inscriptions, annonces | Public ; organisateur (nom et type de compte des inscrits) | Inscriptions et annonces : 12 mois après la fin ; révisions : 6 mois ; événements clos : 12 mois | Validation avant publication, lien jamais envoyé par email |
| T10 | Blog | Publier des articles | Contrat | M, É | Articles, révisions, images, nom d'auteur | Public | Révisions : 6 mois ; brouillons abandonnés, refusés ou dépubliés : 12 mois | Validation avant publication, liens `nofollow ugc` |
| T11 | Suivi administratif | Échéances, rappels, seuils, estimation | Contrat | M (prestataires, entrepreneurs) | Profil d'entreprise, SIREN, TVA, chiffre d'affaires, notes (chiffrés) | Aucun (pas même les admins) | Échéances, alertes, parcours : 36 mois ; chiffre d'affaires : 6 années civiles | Chiffrement, export JSON, purge annoncée |
| T12 | Workspaces et copilote | Organiser un projet, suggestions par IA | Contrat (à la demande) | M (porteurs) | Contenus chiffrés ; contenu expurgé envoyé à l'IA ; journal des appels sans contenu | Mistral AI si activé | Contenus : durée du compte ; suggestions : 90 jours ; journal des appels : 13 mois | Liste blanche, expurgation, aperçu avant envoi, IA désactivée par défaut |
| T13 | Signalements, modération, recours (DSA) | Traiter les signalements, motiver, recours, autorités | Obligation légale (6.1.c, règlement 2022/2065) ; intérêt légitime | V, M, S | Signalement, copie textuelle, identité du signalant sans compte (chiffrée), décisions, recours, transmissions | Équipe ; auteur (exposé des motifs, sans l'identité du signalant) ; autorités | Identité et copie : 12 mois après clôture ; dossiers : 24 mois après fin du délai de recours | Examen humain, aucune image d'abus sur mineurs réaffichée, jeton de suivi haché |
| T14 | Journal d'audit de l'administration | Tracer les décisions de l'équipe | Intérêt légitime | É, M | Admin, action, objet, motif | Équipe | 24 mois | Lignes jamais modifiées, identité du membre en empreinte |
| T15 | Premium et facturation | Gérer l'abonnement, facturer, prévenir les abus de l'essai | Contrat ; obligation légale (factures) ; intérêt légitime (essai) | M | Périodes Premium ; (lot 10) identifiants Stripe, factures, empreinte de carte | Stripe ; expert-comptable | Durée du compte ; factures : [À COMPLÉTER : durée légale] | Aucune donnée de carte chez Loopartner |
| T16 | Emails transactionnels | Informer des événements du service | Contrat ; préférences pour les catégories facultatives | M, S | Email, nom, contenu minimal | Resend | Durée de la file d'attente | Contenus minimaux, emails sensibles chiffrés dans la file |
| T17 | Médias | Images de profil, logos, couvertures | Contrat | M | Images réencodées en WebP, sans métadonnées | Public | Jusqu'à remplacement ; orphelins : 24 h | Contrôle du format réel, réencodage |
| T18 | Abonnements d'agenda | Afficher l'agenda dans un logiciel tiers | Contrat | M | Jeton (empreinte HMAC), titres et dates | Logiciel d'agenda de l'utilisateur | Jusqu'à révocation | Pas de montant, note ni lieu dans le flux |

## Analyses d'impact (AIPD)

| Traitement | Statut | Commentaire |
|---|---|---|
| T12 (copilote, IA générative) | [À FAIRE avant l'activation de l'IA] | Décision 70 ; la CNIL recommande une AIPD en cas de doute pour les systèmes génératifs |
| T13 (signalements, données d'infractions) | [À ÉVALUER par le juriste] | Données relatives à des infractions possibles (art. 10 RGPD) |
| T5, T6 (mise en relation à grande échelle) | [À ÉVALUER] | Critères de la liste CNIL à vérifier selon le volume |

## Violations de données

Procédure : évaluer le risque, notifier la CNIL dans les 72 heures si la violation présente un
risque, informer les personnes en cas de risque élevé, consigner toute violation (même non
notifiée) dans un registre des violations [À CRÉER].
