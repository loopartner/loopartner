<?php

namespace App\Modules\Moderation\Enums;

/**
 * Fondement d'une restriction (DSA art. 17 §3 d et e) : contenu illicite (référence
 * juridique obligatoire) ou contraire aux conditions générales (clause obligatoire).
 */
enum DecisionGround: string
{
    case Illegal = 'illegal';
    case Terms = 'terms';
}
