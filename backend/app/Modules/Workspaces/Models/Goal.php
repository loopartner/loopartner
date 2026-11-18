<?php

namespace App\Modules\Workspaces\Models;

use Carbon\CarbonInterface;
use Database\Factories\Modules\Workspaces\GoalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Objectif d'un workspace (titre et description chiffrés, date cible, atteint ou non). Jamais
 * envoyé à l'IA.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $title
 * @property string|null $description
 * @property CarbonInterface|null $target_date
 * @property CarbonInterface|null $achieved_at
 * @property int $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[UseFactory(GoalFactory::class)]
#[Fillable(['title', 'description', 'target_date'])]
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    protected $table = 'workspace_goals';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'description' => 'encrypted',
            'target_date' => 'date',
            'achieved_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
