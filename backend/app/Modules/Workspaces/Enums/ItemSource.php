<?php

namespace App\Modules\Workspaces\Enums;

/**
 * Origine d'une étape, d'une tâche ou d'un cahier des charges : saisi par l'utilisateur, issu
 * du copilote (IA, marqué ai_generated même après modification, décision 66) ou d'un modèle
 * de projet.
 */
enum ItemSource: string
{
    case User = 'user';
    case Copilot = 'copilot';
    case Template = 'template';
}
