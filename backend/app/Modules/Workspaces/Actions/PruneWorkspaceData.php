<?php

namespace App\Modules\Workspaces\Actions;

use App\Modules\Workspaces\Models\CopilotSuggestion;

/**
 * Minimisation du module Workspaces (décision 65 ; workspaces:prune, chaque nuit) : les
 * suggestions du copilote (réponses de l'IA) sont supprimées
 * security.retention.copilot_suggestions_days après leur génération, appliquées ou non (le
 * contenu appliqué est devenu celui du workspace). Les workspaces et leur contenu restent tant
 * que le compte existe ; le décompte des générations aussi (quota gratuit à vie).
 */
class PruneWorkspaceData
{
    /**
     * @return array{suggestions: int}
     */
    public function handle(): array
    {
        $cutoff = now()->subDays((int) config('security.retention.copilot_suggestions_days'));

        return ['suggestions' => CopilotSuggestion::query()->where('created_at', '<', $cutoff)->delete()];
    }
}
