<?php

namespace App\Modules\Workspaces\Models;

use App\Modules\Workspaces\Enums\SuggestionKind;
use App\Modules\Workspaces\Enums\SuggestionStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Suggestion du copilote : réponse de l'IA **déjà nettoyée** (Support/CopilotOutput), chiffrée
 * en base, que l'utilisateur applique ou écarte. Purgée 90 jours après sa génération
 * (security.retention.copilot_suggestions_days, décision 65). Écrite seulement par les
 * Actions du copilote (aucun champ assignable).
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int|null $step_id
 * @property SuggestionKind $kind
 * @property SuggestionStatus $status
 * @property array<string, mixed> $content
 * @property string $prompt_version
 * @property string $provider
 * @property string $model
 * @property CarbonInterface|null $applied_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Step|null $step
 */
class CopilotSuggestion extends Model
{
    protected $table = 'workspace_copilot_suggestions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SuggestionKind::class,
            'status' => SuggestionStatus::class,
            'content' => 'encrypted:array',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Step, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }
}
