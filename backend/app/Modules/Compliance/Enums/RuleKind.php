<?php

namespace App\Modules\Compliance\Enums;

/**
 * Types de règles. Le contenu propre à chaque type est décrit et validé par RulePayload.
 */
enum RuleKind: string
{
    // Statut juridique proposé dans le questionnaire (activités, régimes de TVA, options).
    case LegalForm = 'legal_form';

    // Échéance récurrente ou ponctuelle, avec ses tâches.
    case Deadline = 'deadline';

    // Seuil de chiffre d'affaires (micro-entreprise, franchise de TVA…).
    case Threshold = 'threshold';

    // Taux (cotisations, impôt) utilisé pour l'estimation premium.
    case Rate = 'rate';

    // Parcours type (« créer mon entreprise »…) : étapes ordonnées.
    case Journey = 'journey';
}
