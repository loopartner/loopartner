<?php

namespace App\Modules\Marketplace\Enums;

/**
 * Nature de la somme versée d'avance : un acompte engage les deux parties ; des arrhes
 * permettent à chacune de se dédire (fiche F31144). À faire valider par le juriste.
 */
enum DepositKind: string
{
    case Deposit = 'acompte';
    case Earnest = 'arrhes';
}
