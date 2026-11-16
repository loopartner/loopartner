# Abonnement Premium : textes destinés aux utilisateurs (brouillon)

> **BROUILLON — À FAIRE VALIDER PAR UN JURISTE AVANT TOUTE MISE EN LIGNE.**
> Ces textes ont été rédigés par l'équipe technique pour le lot Billing (décisions 4, 5 et 18,
> `docs/v1/DECISIONS.md`). Ils ne constituent pas un avis juridique. Chaque point marqué
> **[JURISTE]** doit être confirmé ou corrigé. Les valeurs entre accolades (`{prix_ttc}`) sont
> remplies par l'application à partir de Stripe et de la configuration : aucun montant ni aucune
> durée n'est écrit dans le code.

## Variables

| Variable | Origine |
|---|---|
| `{prix_ttc}` | Prix TTC du plan choisi, lu sur le prix Stripe (TVA incluse) |
| `{periodicite}` | « par mois » ou « par an » |
| `{duree_essai}` | `billing.trial_days` (30 par défaut) |
| `{date_premier_prelevement}` | Fin de l'essai (ou aujourd'hui sans essai), date locale de l'utilisateur |
| `{date_rappel}` | `{date_premier_prelevement}` moins `billing.trial_reminder_days` (7 par défaut) |
| `{date_renouvellement}` | Fin de la période annuelle en cours |
| `{date_limite_resiliation}` | Dernier jour pour résilier sans renouvellement (veille de `{date_renouvellement}`) |
| `{lien_annulation}` | Page « Abonnement » du compte (`FRONTEND_URL/account/billing`) |
| `{lien_cgv}` | Version en vigueur des CGV (`FRONTEND_URL/legal/sales`) |
| `{date_fin_premium}` | Fin de l'essai ou de la période payée |

## 1. Écran d'information avant la souscription (frontend)

Affiché avant la redirection vers Stripe Checkout, à partir de `GET /me/billing/preview`.

### 1.a Avec droit à l'essai

> **Essayez Premium gratuitement pendant {duree_essai} jours**
>
> - Aujourd'hui : **0 €**. Votre carte est demandée pour la suite de l'abonnement, sans prélèvement.
> - Le **{date_premier_prelevement}**, sauf annulation avant cette date : **{prix_ttc} TTC
>   {periodicite}**, puis à chaque échéance (**reconduction automatique**).
> - Nous vous enverrons un rappel par email le **{date_rappel}**.
> - Vous pouvez annuler à tout moment, en quelques clics, depuis votre compte (rubrique
>   « Abonnement ») : pendant l'essai, aucun prélèvement ; ensuite, Premium reste actif jusqu'à la
>   fin de la période payée.
> - Un seul essai gratuit par personne.
>
> ☐ J'ai lu et j'accepte les [conditions générales de vente]({lien_cgv}).
>
> [Continuer vers le paiement sécurisé]

### 1.b Sans droit à l'essai (essai déjà utilisé)

> **Vous avez déjà bénéficié de l'essai gratuit : vous serez débité aujourd'hui.**
>
> - Aujourd'hui : **{prix_ttc} TTC**, puis **{prix_ttc} TTC {periodicite}** à chaque échéance
>   (**reconduction automatique**), sauf résiliation.
> - Vous pouvez résilier à tout moment depuis votre compte : Premium reste actif jusqu'à la fin de
>   la période payée.
> - Droit de rétractation : 14 jours à compter de la souscription, depuis votre compte (bouton
>   « Me rétracter »). **[JURISTE]** texte de l'exécution immédiate ci-dessous.
>
> ☐ J'ai lu et j'accepte les [conditions générales de vente]({lien_cgv}).
> ☐ Je demande que Premium soit activé immédiatement, avant la fin du délai de rétractation.
>   **[JURISTE]** (article L221-25 du Code de la consommation : demande expresse ; conséquence
>   d'une rétractation après exécution, voir § 8).
>
> [Payer {prix_ttc} et activer Premium]

## 2. Texte affiché sur Stripe Checkout

Paramètres `custom_text` et `consent_collection.terms_of_service = required` (1 200 caractères au
plus par texte).

- **Au-dessus du bouton (avec essai)** : « Aucun prélèvement aujourd'hui. Premier prélèvement de
  {prix_ttc} TTC le {date_premier_prelevement}, puis {prix_ttc} TTC {periodicite}, sauf annulation
  avant cette date depuis votre compte Loopartner. »
- **Au-dessus du bouton (sans essai)** : « Vous serez débité de {prix_ttc} TTC aujourd'hui, puis
  {prix_ttc} TTC {periodicite}, sauf résiliation depuis votre compte Loopartner. »
- **Case à cocher** : « J'accepte les [conditions générales de vente de Loopartner]({lien_cgv}). »
- **[JURISTE]** : vérifier que le texte de Stripe sur la réutilisation de la carte
  (`payment_method_reuse_agreement`) convient, ou le remplacer.

## 3. Email de rappel avant la fin de l'essai

Envoyé `billing.trial_reminder_days` jours avant la fin de l'essai (7 par défaut), une seule fois.
Catégorie de notification obligatoire `billing.account`.

> **Objet** : Votre essai Premium se termine le {date_premier_prelevement}
>
> Bonjour,
>
> Votre essai gratuit de Loopartner Premium se termine le **{date_premier_prelevement}**.
>
> Sans action de votre part, votre abonnement commencera à cette date et **{prix_ttc} TTC**
> seront prélevés sur votre carte, puis **{prix_ttc} TTC {periodicite}**.
>
> Vous ne souhaitez pas continuer ? Annulez avant le {date_premier_prelevement} en quelques clics :
> **[Gérer mon abonnement]({lien_annulation})**. Aucun montant ne sera prélevé.
>
> L'équipe Loopartner

## 4. Email d'information avant le renouvellement annuel

**Délai** (article L215-1 du Code de la consommation, version en vigueur depuis le
18 août 2022, consultée sur Légifrance le 2026-11-16) : pour un contrat de prestation de services
à durée déterminée avec reconduction tacite, le professionnel informe le consommateur par écrit
**au plus tôt trois mois et au plus tard un mois avant le terme** de la période autorisant le
rejet de la reconduction, par lettre nominative ou courrier électronique dédiés, la date limite
de non-reconduction figurant **dans un encadré apparent**. Sans cette information, le
consommateur peut résilier gratuitement à tout moment après la reconduction.
Source : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000034072591/

Proposition : envoi **45 jours** avant le renouvellement (`billing.renewal_notice_days`, réglage
contrôlé entre 31 et 89 jours). **[JURISTE]** : confirmer que l'abonnement annuel entre dans
L215-1, que l'email suffit (« courrier électronique dédié »), et le cas de l'abonnement mensuel
(proposé comme contrat résiliable à tout moment, sans information de reconduction).

> **Objet** : Renouvellement de votre abonnement Premium annuel le {date_renouvellement}
>
> Bonjour,
>
> ┌──────────────────────────────────────────────────────────────┐
> │ Vous pouvez refuser la reconduction de votre abonnement       │
> │ jusqu'au **{date_limite_resiliation}** inclus.                │
> └──────────────────────────────────────────────────────────────┘
>
> Votre abonnement Loopartner Premium annuel sera **renouvelé automatiquement le
> {date_renouvellement}** pour un an, au prix de **{prix_ttc} TTC**, prélevé sur votre carte à
> cette date.
>
> Pour ne pas le renouveler, résiliez en quelques clics avant le {date_limite_resiliation} :
> **[Gérer mon abonnement]({lien_annulation})**. Premium restera actif jusqu'au
> {date_renouvellement}.
>
> L'équipe Loopartner

## 5. Confirmation de résiliation

Envoyé dès la résiliation (depuis le compte ou le portail Stripe). **[JURISTE]** : contenu de
la confirmation exigé par l'article L215-1-1 (résiliation en ligne).

### 5.a Pendant l'essai

> **Objet** : Votre essai Premium a bien été annulé
>
> Bonjour,
>
> Nous confirmons l'annulation de votre abonnement Loopartner Premium le {date_resiliation}.
> **Aucun montant ne sera prélevé.** Vous gardez Premium jusqu'à la fin de l'essai, le
> {date_fin_premium}, puis votre compte repasse au plan gratuit sans perte de données.

### 5.b Après l'essai

> **Objet** : Votre abonnement Premium a bien été résilié
>
> Bonjour,
>
> Nous confirmons la résiliation de votre abonnement Loopartner Premium le {date_resiliation}.
> **Aucun nouveau prélèvement ne sera effectué.** Vous gardez Premium jusqu'à la fin de la période
> payée, le {date_fin_premium}, puis votre compte repasse au plan gratuit sans perte de données.
> Vos factures restent disponibles dans votre compte.

## 6. Essai refusé (carte déjà utilisée pour un essai)

> **Objet** : Votre essai Premium n'a pas pu démarrer
>
> Bonjour,
>
> Cette carte a déjà été utilisée pour un essai gratuit de Loopartner Premium. L'essai est limité
> à un par personne : **aucun montant n'a été prélevé** et aucun abonnement n'a été créé.
> Vous pouvez souscrire sans essai depuis votre compte ; le montant vous sera alors indiqué avant
> le paiement.

## 7. Échec de paiement

> **Objet** : Le paiement de votre abonnement Premium a échoué
>
> Bonjour,
>
> Le prélèvement de {prix_ttc} TTC du {date_echec} n'a pas abouti. Nous réessaierons
> automatiquement dans les prochains jours. Pour éviter l'interruption de Premium, mettez à jour
> votre carte : **[Gérer mon abonnement]({lien_annulation})**.
> Sans paiement, votre compte repassera au plan gratuit, sans perte de données.

## 8. Rétractation et remboursement (proposition)

**[JURISTE] — tout ce paragraphe.** Sources consultées le 2026-11-16 : article L221-24
(remboursement sous 14 jours) ; annexe à l'article R221-3 modifiée par le décret n° 2026-3 du
5 janvier 2026, en vigueur depuis le 19 juin 2026, qui prévoit la mention d'une **fonction de
rétractation en ligne** ; selon des sources secondaires (non vérifiées sur le texte), l'ordonnance
n° 2026-2 du 5 janvier 2026 modifie l'article L221-21 pour imposer cette fonction aux contrats
conclus en ligne.
Sources : https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000032226828 et
https://www.legifrance.gouv.fr/codes/article_lc/LEGIARTI000053303407

Proposition, pour éviter les litiges :

1. **Délai de rétractation de 14 jours** à compter de la souscription, exercé par un bouton
   « Me rétracter » dans le compte (fonction de rétractation en ligne), visible pendant tout le
   délai, avec confirmation par email.
2. **Pendant l'essai** : la rétractation met fin immédiatement à l'abonnement et à Premium ;
   rien n'a été prélevé.
3. **Sans essai** (débit immédiat) : la rétractation met fin à Premium et le paiement est
   **remboursé en totalité** sous 14 jours, sur la carte utilisée (geste plus simple que le
   calcul au prorata permis par L221-25).
4. **Geste commercial** (hors obligation légale, à confirmer) : remboursement intégral, sur
   demande depuis le compte ou par email, du **premier prélèvement après l'essai** et du
   **prélèvement d'un renouvellement annuel**, s'il est demandé dans les 14 jours qui suivent ;
   Premium s'arrête alors immédiatement. Un seul remboursement de ce type par personne et par an.
5. Aucun autre remboursement au prorata en cas de résiliation : Premium reste actif jusqu'à la
   fin de la période payée.

## 9. Clauses à prévoir dans les CGV

**[JURISTE] — liste à compléter et à rédiger.**

1. Identité du vendeur (Loopartner), contact, médiateur de la consommation.
2. Description de l'offre Premium : avantages selon le type de compte, renvoi à une page tenue
   à jour ; évolution des avantages et information préalable.
3. Prix TTC, TVA du pays du client (guichet unique OSS), périodicité mensuelle ou annuelle,
   modification du prix (information préalable, droit de résilier).
4. Essai gratuit : durée, une seule fois par personne (contrôle par compte et par carte, motif :
   prévention des abus), carte demandée, date du premier prélèvement, rappel avant la fin.
5. Reconduction automatique, information avant renouvellement annuel (L215-1), résiliation en
   ligne à tout moment (L215-1-1), effet à la fin de la période en cours.
6. Paiement par Stripe (prestataire de paiement), authentification forte, aucune donnée de carte
   conservée par Loopartner, factures disponibles dans le compte.
7. Échec de paiement : nouvelles tentatives automatiques, retour au plan gratuit sans perte de
   données.
8. Droit de rétractation de 14 jours, fonction de rétractation en ligne, exécution immédiate à la
   demande expresse (L221-25), modalités de remboursement (§ 8).
9. Suppression du compte : annulation immédiate de l'abonnement, plus aucun prélèvement ; sort
   de la période payée en cours **[JURISTE]** (remboursement ou non).
10. Clients professionnels (prestataires, entrepreneurs) : numéro de TVA, autoliquidation hors
    de France, factures électroniques **[EXPERT-COMPTABLE]**.
11. Conservation des données de facturation (durée légale) et renvoi à la politique de
    confidentialité.
12. Responsabilité, disponibilité du service, droit applicable et juridiction.

## 10. Points à faire valider (synthèse)

| Point | Qui |
|---|---|
| Application de L215-1 à l'abonnement annuel ; délai de 45 jours ; cas du mensuel | juriste |
| Contenu de la confirmation de résiliation (L215-1-1) | juriste |
| Fonction de rétractation en ligne (ordonnance 2026-2) et texte de l'exécution immédiate | juriste |
| Politique de remboursement (§ 8) | juriste |
| Contrôle de l'essai par empreinte de carte (information dans les CGV et la politique de confidentialité) | juriste |
| TVA OSS, Stripe Tax, prix TTC identique dans toute l'UE | expert-comptable |
| Facturation électronique et e-reporting (clients professionnels et particuliers) | expert-comptable |
| Durée de conservation des données de facturation | expert-comptable |
