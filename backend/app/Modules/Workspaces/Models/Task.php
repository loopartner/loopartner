<?php

namespace App\Modules\Workspaces\Models;

use App\Modules\Workspaces\Enums\ItemSource;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Workspaces\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tâche d'une étape : titre et note chiffrés, échéance, faite ou non, origine. La note n'est
 * jamais envoyée à l'IA.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int $step_id
 * @property string $title
 * @property string|null $notes
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $completed_at
 * @property ItemSource $source
 * @property CarbonInterface|null $ai_generated_at
 * @property int $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read Step $step
 */
#[UseFactory(TaskFactory::class)]
#[Fillable(['title', 'notes', 'due_date'])]
class Task extends Model
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    protected $table = 'workspace_tasks';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'source' => 'user',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'notes' => 'encrypted',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'source' => ItemSource::class,
            'ai_generated_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Step, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
