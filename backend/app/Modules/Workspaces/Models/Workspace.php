<?php

namespace App\Modules\Workspaces\Models;

use App\Models\User;
use App\Modules\Workspaces\Enums\WorkspaceStatus;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Workspaces\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Workspace : espace **privé** de pilotage d'un projet d'un particulier ou d'un entrepreneur
 * (décisions 3 et 63). Visible de son seul propriétaire (404 pour tout autre utilisateur,
 * admins compris).
 *
 * - title, description : chiffrés en base (APP_KEY).
 * - activated_at : choisit les workspaces modifiables quand le plan en permet moins que le
 *   nombre de workspaces actifs (fin du Premium) : les plus récemment activés le restent.
 * - Champs fixés par les Actions (forceFill) : uuid, user_id, status, template_code,
 *   linked_project_id, activated_at, archived_at.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $title
 * @property string|null $description
 * @property string|null $country
 * @property int|null $budget_min
 * @property int|null $budget_max
 * @property string $currency
 * @property bool|null $remote_ok
 * @property CarbonInterface|null $desired_start_date
 * @property WorkspaceStatus $status
 * @property string|null $template_code
 * @property int|null $linked_project_id
 * @property CarbonInterface $activated_at
 * @property CarbonInterface|null $archived_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[UseFactory(WorkspaceFactory::class)]
#[Fillable(['title', 'description', 'country', 'budget_min', 'budget_max', 'currency', 'remote_ok', 'desired_start_date'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'EUR',
        'status' => 'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title' => 'encrypted',
            'description' => 'encrypted',
            'budget_min' => 'integer',
            'budget_max' => 'integer',
            'remote_ok' => 'boolean',
            'desired_start_date' => 'date',
            'status' => WorkspaceStatus::class,
            'activated_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->status === WorkspaceStatus::Archived;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Goal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(Goal::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Step, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(Step::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Specification, $this>
     */
    public function specifications(): HasMany
    {
        return $this->hasMany(Specification::class);
    }

    /**
     * @return HasMany<CopilotSuggestion, $this>
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(CopilotSuggestion::class);
    }
}
