<?php

namespace App\Modules\Workspaces\Models;

use App\Models\ServiceCategory;
use App\Modules\Workspaces\Enums\ItemSource;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Workspaces\StepFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Étape de la feuille de route d'un workspace : titre et description chiffrés, échéance,
 * catégorie de prestataires utile (reliée à GET /providers), origine (source) et date de
 * génération par l'IA (ai_generated_at, conservée même après modification).
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property string $title
 * @property string|null $description
 * @property CarbonInterface|null $due_date
 * @property CarbonInterface|null $completed_at
 * @property int|null $service_category_id
 * @property ItemSource $source
 * @property CarbonInterface|null $ai_generated_at
 * @property int $position
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read ServiceCategory|null $category
 */
#[UseFactory(StepFactory::class)]
#[Fillable(['title', 'description', 'due_date'])]
class Step extends Model
{
    /** @use HasFactory<StepFactory> */
    use HasFactory;

    protected $table = 'workspace_steps';

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
            'description' => 'encrypted',
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'source' => ItemSource::class,
            'ai_generated_at' => 'datetime',
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

    /**
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }
}
