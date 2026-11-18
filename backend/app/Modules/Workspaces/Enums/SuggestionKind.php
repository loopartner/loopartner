<?php

namespace App\Modules\Workspaces\Enums;

/**
 * Type de génération du copilote : feuille de route (étapes, tâches, catégories) ou cahier des
 * charges (Premium).
 */
enum SuggestionKind: string
{
    case Roadmap = 'roadmap';
    case Specification = 'specification';
}
