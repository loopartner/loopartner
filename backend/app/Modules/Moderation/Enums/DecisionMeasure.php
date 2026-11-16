<?php

namespace App\Modules\Moderation\Enums;

/**
 * Mesure décidée (DSA art. 17 §3 a) : restriction de la visibilité du contenu (masqué pour
 * tous, retrait compris), ou aucune mesure.
 */
enum DecisionMeasure: string
{
    case RestrictVisibility = 'restrict_visibility';
    case NoAction = 'no_action';

    public function restricts(): bool
    {
        return $this === self::RestrictVisibility;
    }
}
