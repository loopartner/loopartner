<?php

namespace App\Modules\Compliance\Enums;

/**
 * Cycle de vie d'une règle. Seule une règle vérifiée est montrée aux utilisateurs.
 *
 * draft → verified → retired. Une règle vérifiée n'est jamais modifiée sur place : sa
 * modification est un nouveau brouillon (replaces_rule_id), qui la retire à sa vérification.
 */
enum RuleStatus: string
{
    case Draft = 'draft';
    case Verified = 'verified';
    case Retired = 'retired';
}
