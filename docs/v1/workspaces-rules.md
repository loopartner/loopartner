# Workspaces, copilote et brique d'IA : règles, textes et points à faire valider

Lot 5 de la V1 (décisions 60 à 70 de `docs/v1/DECISIONS.md`). Ce document décrit :

- ce que fait la plateforme pour les workspaces, le parcours d'accueil, le copilote de projet et
  la brique d'IA du noyau ;
- **exactement** ce qui est envoyé au service d'IA ;
- les textes affichés aux utilisateurs ;
- les étapes à faire par l'exploitant avant d'activer l'IA ;
- les points à faire valider par le juriste (section 8, tableau unique à lui transmettre tel quel).

**Statut : brouillon technique, à faire valider.** Les textes cités sont ceux du code ; ils
peuvent changer après validation.

## 1. Sources consultées

Toutes les pages ont été consultées le **2026-11-18**. L'outil de lecture ne restitue que des
résumés et des citations courtes : chaque formulation exacte est à relire sur la page.

**Limite de la vérification** : EUR-Lex n'a pas pu être lu directement (page vide). Le texte du
règlement (UE) 2024/1689 a été lu dans la **version consolidée publiée par la Commission** sur
l'AI Act Service Desk, qui indique reprendre la version consolidée d'EUR-Lex au 27 juillet 2026.
Cette version est **en anglais** : la version française officielle reste à relire sur EUR-Lex.

### Règlement (UE) 2024/1689 sur l'intelligence artificielle

| Sujet | Constat | Source |
|---|---|---|
| Art. 3(3) et 3(4) | Le fournisseur met un système d'IA sur le marché ou en service sous son propre nom, à titre onéreux ou gratuit. Le déployeur utilise un système d'IA sous son autorité. | https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-3 |
| Art. 3(68) | Fournisseur en aval : fournisseur d'un système d'IA qui intègre un modèle d'IA, que le modèle soit le sien ou fourni par une autre entité par contrat | même page |
| Art. 50 §1 | Les systèmes destinés à interagir directement avec des personnes doivent les informer qu'elles interagissent avec une IA, sauf si c'est évident pour une personne raisonnablement informée, attentive et avisée | https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-50 |
| Art. 50 §2 | Les sorties d'un système qui génère du texte synthétique sont marquées dans un format lisible par machine et détectables comme générées artificiellement. Exception : fonction d'assistance à l'édition standard, ou entrée peu modifiée. | même page |
| Art. 50 §4, 2e alinéa | Vise le texte généré **publié** pour informer le public sur des questions d'intérêt public (obligation du déployeur) | même page |
| Art. 50 §5 | L'information est claire et distincte, donnée au plus tard lors de la première interaction ou exposition, et accessible | même page |
| Art. 4 | Fournisseurs et déployeurs prennent des mesures pour la maîtrise de l'IA par leur personnel. Modifié par l'Omnibus : aucun niveau précis n'est exigé. Applicable depuis le 2 février 2025. | https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-4 |
| Art. 113 | Application générale (dont l'art. 50) : 2 août 2026 ; chapitres I et II : 2 février 2025 ; chapitre V : 2 août 2025 | https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-113 |
| Omnibus numérique sur l'IA | Règlement (UE) 2026/1744, JO L du 24 juillet 2026 : fiche identifiée, contenu non lu | https://eur-lex.europa.eu/eli/reg/2026/1744/oj/eng |
| Délai transitoire de l'art. 50 §2 | Nouvel art. 111 §4 : il ne vaut que pour les systèmes mis sur le marché **avant le 2 août 2026** (mise en conformité au 2 décembre 2026). **Le copilote n'en bénéficie pas.** | https://ai-act-service-desk.ec.europa.eu/en/ai-act/article-111 |
| Annexe III (haut risque) | Biométrie, infrastructures critiques, éducation, emploi et accès au travail indépendant, services essentiels (crédit, assurance…), répression, migration, justice. Un assistant de planification de projet n'y entre pas. | https://ai-act-service-desk.ec.europa.eu/en/ai-act/annex-3 |
| Lignes directrices sur l'art. 50 | C(2026) 5054, publiées le 20 juillet 2026. **Contenu non lu** (PDF illisible). | https://digital-strategy.ec.europa.eu/en/news/commission-publishes-guidelines-transparency-obligations-providers-and-deployers-certain-ai-systems |
| Code de bonnes pratiques | Code volontaire sur la transparence des contenus générés (art. 50 §2 et §4) | https://digital-strategy.ec.europa.eu/en/policies/code-practice-ai-generated-content |

### RGPD, CNIL, transferts

| Sujet | Constat | Source |
|---|---|---|
| IA générative (questions-réponses du 18 juillet 2024) | Interroger le fournisseur sur sa conformité ; **désactiver la réutilisation des données d'usage** par le fournisseur ; en mode API, éviter autant que possible les données personnelles ; préciser les responsabilités dans le contrat ; encadrer les transferts hors UE ; associer le DPO ; AIPD « le cas échéant » | https://www.cnil.fr/fr/les-questions-reponses-de-la-cnil-sur-lutilisation-dun-systeme-dia-generative |
| AIPD et IA (8 avril 2024) | L'IA ne relève pas systématiquement de l'usage innovant, mais les systèmes génératifs sont cités en exemple ; en cas de doute, la CNIL recommande une AIPD | https://www.cnil.fr/fr/realiser-une-analyse-dimpact-si-necessaire |
| RGPD art. 9 | Interdiction de principe des données sensibles (santé, opinions…) | https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre2 |
| RGPD art. 22 | Décision fondée exclusivement sur un traitement automatisé et produisant des effets juridiques ou significatifs. La suggestion du copilote n'en produit aucun. | https://www.cnil.fr/fr/reglement-europeen-protection-donnees/chapitre3 |
| Transferts vers les États-Unis | Tribunal, T-553/23 *Latombe*, 3 septembre 2025 : décision d'adéquation (UE) 2023/1795 maintenue. Pourvoi C-703/25 P (JO C/2025/6610) : **état actuel non vérifié**. | https://curia.europa.eu/site/upload/docs/application/pdf/2025-09/cp250106en.pdf ; https://eur-lex.europa.eu/eli/C/2025/6610/oj |
| Obligations RGPD des entreprises | Registre ; contrat de sous-traitance (objet, nature, finalité, durée) ; AIPD avant la mise en œuvre ; transferts hors UE (adéquation ou clauses types). Fiche vérifiée le 26 avril 2024, sans mention de l'IA. | https://entreprendre.service-public.gouv.fr/vosdroits/F24270 |

### Droit français

| Sujet | Constat | Source |
|---|---|---|
| Consultation juridique | Loi n° 71-1130, art. 54 : nul ne peut, à titre habituel et rémunéré, donner des consultations juridiques sans remplir les conditions prévues | https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000039280601 |
| Sanction | Loi n° 71-1130, art. 66-2 : renvoi aux peines de l'art. 72 (la page indique 15 000 € d'amende et un an d'emprisonnement) | https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000006903493 |
| Expertise comptable | Ordonnance n° 45-2138, art. 2 : tenue, centralisation, surveillance et redressement des comptabilités | https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000032364676 |
| Exercice illégal | Ordonnance n° 45-2138, art. 20 : exercice illégal (peines de la version en vigueur non vérifiées) | https://www.legifrance.gouv.fr/loda/article_lc/LEGIARTI000045178543 |
| Classement des offres | C. consom. L111-7 II : information loyale sur le classement des offres. Le copilote suggère des **catégories**, jamais des prestataires précis : hors champ tant que cela reste vrai. | https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000049571125 |

**Non vérifié** : texte français du règlement sur l'IA sur EUR-Lex ; contenu des lignes
directrices C(2026) 5054 ; art. 3(1), 3(63) et 25 du règlement ; état du pourvoi C-703/25 P ;
peines de l'art. 20 de l'ordonnance n° 45-2138 dans sa version en vigueur ; actualité
entreprendre.service-public.gouv.fr A18475 (calendrier antérieur à l'Omnibus).

### Fournisseurs d'IA comparés (décision 60)

Requête type : 3 000 jetons en entrée, 2 000 en sortie. Prix en USD par million de jetons.

| Critère | Mistral AI (Small 4) | Anthropic (Claude Haiku 5.5) | OpenAI (gpt-6-luna) |
|---|---|---|---|
| Prix (entrée / sortie) | 0,15 / 0,60 ; ×1,1 sur l'endpoint UE | 0,10 / 0,50 | 0,10 / 0,50 ; ×1,1 avec la résidence UE |
| Coût d'une requête type | 0,00165 $ (0,0018 $ sur l'endpoint UE) | 0,0013 $ | 0,0013 $ (0,0014 $ avec la résidence UE) |
| Traitement dans l'UE | Oui, garanti par `api.eu.mistral.ai` | Non en direct (stockage aux États-Unis) ; possible par AWS Bedrock (profil UE) | Oui par `eu.api.openai.com`, réservé aux organisations éligibles |
| Entraînement sur nos données | Exclu, sauf si l'opt-out n'est pas désactivé : **réglage à désactiver** | Non par défaut | Non par défaut |
| Contrat de sous-traitance | Incorporé aux conditions commerciales | Incorporé automatiquement (clauses types) | Existe (OpenAI Ireland) ; incorporation non vérifiée |
| Conservation chez le fournisseur | 30 jours (surveillance des abus) ; ZDR sur demande, plans payants | Deux pages officielles se contredisent (0 ou 30 jours) ; ZDR sur demande | Jusqu'à 30 jours ; ZDR sur approbation |
| SDK PHP officiel | Non | Oui (`anthropic-ai/sdk`) | Non |

Sources Mistral :

- https://docs.mistral.ai/inference/regional-inference : endpoint `https://api.eu.mistral.ai`,
  « 1.1× standard list pricing » ; un endpoint régional ne sert que les modèles hébergés dans la
  région, et les garanties tombent si une fonction non prise en charge est utilisée ; le plan
  de contrôle (compte, clés, facturation) n'est pas régional.
- https://docs.mistral.ai/models/mistral-small-4-0-26-03 : identifiant `mistral-small-2603`,
  0,15 et 0,60 $, contexte de 256 000 jetons, sortie structurée, modèle de raisonnement.
- https://docs.mistral.ai/inference/conversations/reasoning : `reasoning_effort` ; avec
  « none », la réponse est une chaîne sans trace de raisonnement.
- https://legal.mistral.ai/terms/commercial-terms-of-service et
  https://legal.mistral.ai/terms/data-processing-addendum : DPA incorporé ; pas d'entraînement
  sauf opt-out non désactivé.
- https://help.mistral.ai/en/articles/455207-can-i-opt-out-of-my-input-or-output-data-being-used-for-training :
  désactivation dans Admin, Privacy, « Anonymous improvement data ».
- https://docs.mistral.ai/admin/monitor-comply/zero-data-retention.md : ZDR.
- https://docs.mistral.ai/resources/sdks : SDK officiels en Python et TypeScript seulement.

Sources Anthropic : https://platform.claude.com/docs/en/about-claude/pricing ;
https://platform.claude.com/docs/en/manage-claude/data-residency ;
https://platform.claude.com/docs/en/manage-claude/api-and-data-retention ;
https://privacy.claude.com.

Sources OpenAI : https://developers.openai.com/api/docs/pricing ;
https://developers.openai.com/api/docs/guides/data-residency ;
https://developers.openai.com/api/docs/guides/your-data.

**À confirmer au premier appel réel** (`php artisan ai:test-connection`, section 7) :

- disponibilité de `mistral-small-2603` sur l'endpoint de l'UE ;
- forme exacte de `response_format` (`json_schema`) et des champs `usage` ;
- facturation de jetons de raisonnement avec `reasoning_effort = none`.

La documentation lue ne les détaille pas ; le pilote les lit de façon défensive.

## 2. Ce que fait la plateforme

- **Workspaces** (particuliers et entrepreneurs) : espaces privés de pilotage d'un projet
  (objectifs, étapes, tâches, avancement, cahiers des charges).
  - Tout autre utilisateur reçoit **404, administrateurs compris**.
  - Titres, descriptions et notes sont chiffrés en base.
  - Aucun contenu n'est public. Le seul moyen de le montrer à autrui est de créer un
    **brouillon** de projet Directory, que l'utilisateur publie ensuite lui-même ; il devient
    alors signalable comme tout projet.
- **Parcours d'accueil** : étapes par rôle calculées sur les données réelles (email vérifié,
  préférences, premier workspace, première tâche, profil prestataire…), sans email.
- **Copilote de projet** : à la demande de l'utilisateur, une IA propose une feuille de route
  (étapes, tâches, catégories de prestataires utiles) ou un cahier des charges.
  - La réponse est une **suggestion** : rien n'est ajouté au workspace sans l'action de
    l'utilisateur.
  - Les catégories proposées sont reliées à la recherche de prestataires
    (`GET /providers?category=…&country=…`) ; jamais un prestataire précis.
- **Brique d'IA du noyau** :
  - plafonds de coût par utilisateur et globaux ;
  - suivi des coûts sans contenu ;
  - IA désactivée par défaut ;
  - appel refusé si le délai d'exécution de PHP est trop court.

## 3. Ce qui est envoyé au service d'IA, exactement

À chaque génération, et seulement à l'initiative de l'utilisateur, le serveur envoie :

1. **Un prompt système fixe**, versionné dans le code. Il contient des consignes générales et
   l'interdiction de donner un avis juridique, fiscal ou comptable ; aucune donnée d'utilisateur.
2. **Le contenu suivant**, construit par une liste blanche et prévisualisable par l'utilisateur
   (`GET /me/workspaces/{uuid}/copilot/preview`) :

```json
{
  "task": "roadmap",
  "language": "fr",
  "account_type": "particulier",
  "project": {
    "title": "Titre du workspace (expurgé)",
    "description": "Description du workspace (expurgée)",
    "country": "FR",
    "remote_ok": true,
    "budget": {"min": 2000, "max": 5000, "currency": "EUR"},
    "desired_start": "2027-03",
    "existing_steps": ["Titres des étapes déjà présentes (expurgés)"]
  },
  "categories": [{"slug": "site-vitrine", "name": "Site vitrine"}]
}
```

Pour un cahier des charges (`"task": "specification"`), le contenu ajoute la catégorie et le
titre de l'étape choisis.

**Expurgation** avant l'envoi et dans la prévisualisation : les adresses email, numéros de
téléphone, IBAN, liens (URL et noms de domaine) et numéros à 9 ou 14 chiffres (SIREN, SIRET) sont
remplacés par `[email]`, `[téléphone]`, `[iban]`, `[lien]` et `[numéro]`.

**Jamais envoyé** : nom, email, identifiant ou uuid de l'utilisateur ou du workspace, adresse IP
de l'utilisateur (le fournisseur ne voit que celle du serveur), ville, notes des tâches,
objectifs, données du suivi administratif, messages, demandes, devis, rendez-vous, et **aucune
donnée d'un autre utilisateur**. Des tests vérifient la liste blanche et l'absence des données
d'un tiers.

**Réponse** : un JSON imposé par un schéma, puis nettoyé par le serveur avant d'être gardé :

- texte brut, sans HTML ni caractères invisibles ;
- liens retirés ;
- longueurs bornées ;
- au plus 12 étapes de 8 tâches ;
- catégories filtrées sur les catégories actives.

Une réponse inexploitable donne 502 `ai_invalid_response` et ne compte pas dans le quota.

## 4. Quotas et coûts

| Droit (particulier, entrepreneur) | Gratuit | Premium |
|---|---|---|
| Workspaces non archivés | 1 | 10 (50 au total) |
| Modèles de projet | non | oui |
| Feuilles de route générées | 2 à vie (une et une régénération) | quota mensuel |
| Cahiers des charges générés | non | quota mensuel |
| Générations | — | 30 par mois, 10 par jour |

- Email vérifié obligatoire pour toute génération (403 `email_not_verified`).
- Plafonds de coût : 0,50 $ par utilisateur et par mois ; 50 $ par mois et 5 $ par jour au
  global.
- Coût estimé avec Mistral Small 4 sur l'endpoint UE : environ 0,002 $ par génération ; environ
  0,004 $ à vie pour un utilisateur gratuit ; environ 0,054 $ par mois pour un utilisateur
  Premium au plafond.

## 5. Textes affichés aux utilisateurs (code : `Workspaces\Support\CopilotTexts`)

**Information sur l'IA** (version `2026-11-18`), renvoyée par `GET /me/copilot`. Sa version doit
être envoyée avec chaque génération (art. 50 §1 et §5) :

> Le copilote utilise un service d'intelligence artificielle (:provider, traitement dans l'Union
> européenne). Quand vous lancez une génération, le titre et la description de votre workspace
> (sans les emails, téléphones, IBAN, liens et numéros d'entreprise, retirés automatiquement),
> son pays, son budget, sa date de début souhaitée, le titre de ses étapes et votre type de
> compte lui sont envoyés. Votre nom, votre email et les données des autres utilisateurs ne lui
> sont jamais envoyés. Le résultat est une proposition générée automatiquement : relisez-la avant
> de l'appliquer.

**Avertissement** (version 1), joint à chaque réponse du copilote (`ai.disclaimer`) :

> Contenu généré par une intelligence artificielle : il peut être incomplet ou inexact, vérifiez-le.
> Ce n'est ni un avis juridique, ni un avis fiscal, ni un conseil comptable : pour ces questions,
> adressez-vous à un professionnel (avocat, expert-comptable).

**Données sensibles**, près du champ de description (`GET /me/copilot`, `sensitive_data_warning`) :

> N'indiquez pas d'information de santé, d'opinion politique ou religieuse ni d'autre donnée
> sensible, ni les coordonnées d'une personne : ce texte peut être envoyé au service d'IA du
> copilote.

**Marquage** : tout élément issu du copilote porte `ai_generated: true` et `source: "copilot"`,
même après modification par l'utilisateur. Chaque réponse du copilote porte l'objet
`ai` (`generated`, `provider`, `model`, `disclaimer`).

## 6. Données et durées de conservation (décision 65)

| Données | Conservation | Ensuite |
|---|---|---|
| Workspace, objectifs, étapes, tâches, cahiers des charges | tant que le compte existe (l'utilisateur les supprime quand il veut) | supprimés avec le compte |
| Suggestion du copilote (réponse de l'IA) | 90 jours après sa génération | supprimée |
| Décompte des générations (date, type) | tant que le compte existe (quota gratuit à vie) | supprimé avec le compte |
| Journal des appels à l'IA (fonctionnalité, modèle, jetons, coût, statut ; jamais le contenu) | 13 mois | supprimé ; détaché du compte dès sa suppression |
| Totaux par jour (sans utilisateur) | sans limite (aucune donnée personnelle) | — |
| État du parcours d'accueil | tant que le compte existe | supprimé avec le compte |
| Chez Mistral AI | 30 jours (surveillance des abus), aucune avec la ZDR | supprimées par Mistral |

## 7. À faire par l'exploitant (avant d'activer l'IA)

L'IA reste désactivée (`AI_DRIVER=disabled`) tant que ces étapes ne sont pas faites.

1. **Hébergement** : ne pas activer l'IA sur l'hébergement mutualisé.
   - L'appel est synchrone et peut durer jusqu'à `AI_TIMEOUT` secondes (45).
   - `max_execution_time` doit être nul ou au moins égal à `AI_TIMEOUT` + 15 secondes.
   - `security:check-config` le vérifie en erreur, et l'API refuse l'appel (503) sinon.
   - À faire sur le VPS ou l'hébergement Laravel géré prévu avant la bêta (décision 10).
2. **Compte Mistral AI** : créer une organisation sur https://console.mistral.ai, avec une adresse
   de l'équipe, puis choisir un plan payant (la ZDR n'existe que sur les plans payants).
3. **Contrat** : enregistrer la version des conditions commerciales et du DPA acceptées (date),
   et consulter la liste des sous-traitants ultérieurs (trust.mistral.ai). Les transmettre au
   juriste (J9).
4. **Entraînement** : dans la console, Admin, Privacy, **désactiver « Anonymous improvement
   data »**. Faire une capture datée de l'écran.
5. **Conservation nulle (ZDR)** : la demander à Mistral (procédure de
   docs.mistral.ai/admin/monitor-comply/zero-data-retention), avec le motif : données de projets
   d'utilisateurs, minimisation RGPD. Garder la réponse.
6. **Clé d'API** : créer une clé dédiée à la production, la mettre dans `backend/.env`
   (`MISTRAL_API_KEY`), jamais dans le dépôt ni dans un ticket. Une clé par environnement.
   Fixer une limite de dépense mensuelle dans la console Mistral, si elle le permet, en plus de
   nos plafonds.
7. **Configuration** dans `backend/.env` :

   ```
   AI_DRIVER=mistral
   AI_MODEL=mistral-small-2603
   MISTRAL_BASE_URL=https://api.eu.mistral.ai
   AI_USER_MONTHLY_BUDGET_CENTS=50
   AI_GLOBAL_MONTHLY_BUDGET_CENTS=5000
   AI_GLOBAL_DAILY_BUDGET_CENTS=500
   ```

   Puis lancer `php artisan config:cache` et `php artisan security:check-config`.
8. **Premier essai** : `php artisan ai:test-connection`.
   - La commande envoie une requête fixe, sans aucune donnée d'utilisateur.
   - Elle vérifie l'endpoint de l'UE, le modèle, la sortie JSON et le décompte des jetons.
   - Comparer ensuite le coût affiché avec la console Mistral.
9. **Avant l'ouverture** : AIPD, politique de confidentialité et points J1 à J11 validés.

## 8. Points à faire valider par le juriste

| # | Point | Proposition actuelle | Base |
|---|---|---|---|
| J1 | Statut de Loopartner au sens du règlement sur l'IA | Fournisseur (en aval) du système d'IA « copilote », et déployeur | Art. 3(3), 3(4), 3(68) |
| J2 | Marquage lisible par machine du texte généré (art. 50 §2) | `ai_generated: true` et objet `ai` dans l'API sur chaque élément issu du copilote, conservés après modification. Le copilote transforme largement l'entrée : l'exception d'édition standard paraît inapplicable. Est-ce suffisant ? (lignes directrices C(2026) 5054 à lire) | Art. 50 §2, art. 111 §4 |
| J3 | Information des utilisateurs (art. 50 §1 et §5) | Texte de la section 5, renvoyé par l'API, dont la version doit être envoyée avec chaque génération (information avant la première utilisation) | Art. 50 §1 et §5 |
| J4 | Avertissement et absence d'avis juridique, fiscal ou comptable | Texte de la section 5 sur chaque réponse ; consigne d'interdiction dans le prompt ; le copilote ne suggère que des catégories de professionnels | Loi n° 71-1130, art. 54 et 66-2 ; ordonnance n° 45-2138, art. 2 et 20 |
| J5 | Données sensibles saisies dans la description | Avertissement de la section 5 ; expurgation des identifiants ; aucune détection des données de santé | RGPD art. 9 ; CNIL, questions-réponses du 18 juillet 2024 |
| J6 | Base légale de l'envoi au fournisseur d'IA | Exécution du contrat (fonctionnalité demandée par l'utilisateur, génération lancée par lui) ; information dans la politique de confidentialité (sous-traitant, finalité, lieu de traitement dans l'UE, conservation) | RGPD art. 6.1.b, 13 |
| J7 | Analyse d'impact (AIPD) et registre | AIPD recommandée avant l'activation (usage innovant ; « en cas de doute ») ; traitement ajouté au registre | RGPD art. 30 et 35 ; CNIL, fiche AIPD du 8 avril 2024 ; F24270 |
| J8 | Durées de conservation | Tableau de la section 6 (décision 65) | RGPD art. 5.1.e |
| J9 | Contrat de sous-traitance avec Mistral AI | DPA incorporé aux conditions commerciales ; opt-out d'entraînement désactivé ; ZDR demandée ; sous-traitants ultérieurs à vérifier ; aucun transfert hors UE avec l'endpoint régional (le plan de contrôle — compte, clés, facturation — peut l'être) | RGPD art. 28, 44 à 46 |
| J10 | Maîtrise de l'IA par l'équipe | Courte formation de l'équipe (fonctionnement, limites, données à ne pas utiliser), tracée | Règlement sur l'IA, art. 4 |
| J11 | Texte généré rendu public par l'utilisateur, et suggestions futures de prestataires | Le copilote ne publie rien ; un brouillon Directory créé à partir d'un texte généré est publié par l'utilisateur sous sa responsabilité (art. 50 §4 : hors champ, ce n'est pas une information d'intérêt public ?). Si un jour le copilote suggère des prestataires précis : critères de classement à publier, sans favoriser les comptes Premium | Art. 50 §4 ; C. consom. L111-7 II |
