<?php

/*
|--------------------------------------------------------------------------
| Module Marketplace (offres, devis, comparateur)
|--------------------------------------------------------------------------
|
| Réglages métier du lot 3 (décisions 36 à 50, docs/v1/DECISIONS.md). Aucun taux de TVA ici :
| les taux sont des règles vérifiées du module Compliance (type vat_rate), lues par l'Action
| publique GetVatRates. Limites de débit et durées de conservation : config/security.php.
| Textes destinés aux utilisateurs et points à faire valider : docs/v1/marketplace-rules.md.
*/

return [

    /*
    | Pays d'établissement des prestataires qui peuvent envoyer des devis (décision 49 : la
    | France seulement en V1), avec la devise des devis et le fuseau qui fixe la fin du jour de
    | validité. Les taux de TVA de ces pays viennent des règles vérifiées.
    */
    'countries' => [
        'FR' => ['currency' => 'EUR', 'timezone' => 'Europe/Paris'],
    ],

    /*
    | Autocertification du prestataire (décision 37) : texte accepté à chaque enregistrement de
    | son identité professionnelle, avec sa version. Changer le texte impose de changer la version :
    | les prestataires devront l'accepter de nouveau avant de publier ou d'envoyer un devis.
    */
    'self_certification' => [
        'version' => '2026-11-17',
        'text' => [
            'fr' => 'Je déclare agir en tant que professionnel, que les informations de mon identité professionnelle sont exactes, et je m\'engage à ne proposer que des services conformes au droit applicable.',
            'en' => 'I declare that I am acting as a professional, that my business identity details are accurate, and I undertake to offer only services that comply with applicable law.',
        ],
    ],

    'offers' => [
        // Offres publiées en même temps, et offres au total (brouillons compris), par prestataire.
        'max_published' => 20,
        'max_total' => 30,
    ],

    'quotes' => [
        // Durée de validité proposée par défaut, et bornes acceptées, en jours.
        'validity_days' => [
            'default' => 30,
            'min' => 1,
            'max' => 180,
        ],
        'max_lines' => 100,
        'max_drafts_per_provider' => 20,
        // Plafonds techniques de saisie (centimes, millièmes) : ils garantissent un calcul en
        // entiers sans dépassement de capacité.
        'max_unit_price_cents' => 1_000_000_000,
        'max_quantity_milli' => 10_000_000,
        'max_total_cents' => 100_000_000_000,
    ],

    /*
    | Droit de rétractation d'un consommateur qui accepte un devis en ligne (Code de la
    | consommation, L221-18 : 14 jours ; décision 42), en jours après l'acceptation.
    */
    'withdrawal_days' => 14,

    /*
    | Comparateur de devis (décisions 33 et 50) : règles explicables, sans IA. Chaque règle est
    | activable ; ses seuils ne sont pas réglementaires (ce sont des repères de lecture).
    */
    'comparison' => [
        'max_quotes' => 10,
        'rules' => [
            'missing_start_date' => ['enabled' => true],
            'missing_duration' => ['enabled' => true],
            'missing_payment_terms' => ['enabled' => true],
            'deposit_requested' => ['enabled' => true],
            'missing_travel_costs' => ['enabled' => true],
            'single_lump_sum' => ['enabled' => true, 'share_percent' => 80],
            'missing_insurance' => ['enabled' => true],
            'validity_ending' => ['enabled' => true, 'days' => 7],
            'price_gap' => ['enabled' => true, 'min_quotes' => 3, 'gap_percent' => 30],
            'missing_line_type' => ['enabled' => true, 'min_quotes' => 3],
            'mixed_units' => ['enabled' => true],
            'earliest_start' => ['enabled' => true],
        ],
    ],

];
