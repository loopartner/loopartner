<?php

namespace App\Modules\Workspaces\Models;

use App\Modules\Workspaces\Enums\SuggestionKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Génération réussie du copilote, comptée pour les quotas (décision 61) : quota gratuit à vie
 * des feuilles de route, quotas mensuel et quotidien du Premium. Gardée tant que le compte
 * existe (une suppression de workspace ou de suggestion ne libère rien) ; aucun contenu.
 *
 * @property int $id
 * @property int $user_id
 * @property SuggestionKind $kind
 * @property CarbonInterface $generated_at
 */
class CopilotEntry extends Model
{
    public $timestamps = false;

    protected $table = 'workspace_copilot_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => SuggestionKind::class,
            'generated_at' => 'datetime',
        ];
    }
}
