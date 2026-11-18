<?php

namespace App\Modules\Workspaces\Enums;

/**
 * Statut d'un workspace : actif (modifiable, compté dans le quota) ou archivé (lecture seule,
 * hors quota, désarchivable).
 */
enum WorkspaceStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
