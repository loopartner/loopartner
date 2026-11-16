<?php

namespace App\Modules\Moderation\Enums;

/**
 * Auteur d'un recours : l'auteur du contenu visé par une restriction, ou le signalant dont le
 * signalement n'a donné lieu à aucune mesure.
 */
enum AppellantKind: string
{
    case Owner = 'owner';
    case Notifier = 'notifier';
}
