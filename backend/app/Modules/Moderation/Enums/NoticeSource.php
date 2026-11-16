<?php

namespace App\Modules\Moderation\Enums;

/**
 * Origine d'un signalement : le formulaire public (tout contenu public, avec ou sans compte),
 * ou la messagerie (message ou demande signalés par leur destinataire, module Directory).
 */
enum NoticeSource: string
{
    case Public = 'public';
    case InApp = 'in_app';
}
