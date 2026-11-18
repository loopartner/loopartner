<?php

namespace App\Modules\Workspaces\Models;

use App\Models\ServiceCategory;
use App\Modules\Workspaces\Enums\ItemSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cahier des charges d'un workspace : titre et sections à liste fermée (Support/SpecificationSections),
 * chiffrés en base ; catégorie de prestataires et étape facultatives ; origine.
 *
 * @property int $id
 * @property string $uuid
 * @property int $workspace_id
 * @property int|null $step_id
 * @property int|null $service_category_id
 * @property string $title
 * @property array<string, string|null> $sections
 * @property ItemSource $source
 * @property CarbonInterface|null $ai_generated_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read ServiceCategory|null $category
 * @property-read Step|null $step
 */
#[Fillable(['title'])]
class Specification extends Model
{
    protected $table = 'workspace_specifications';

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
            'sections' => 'encrypted:array',
            'source' => ItemSource::class,
            'ai_generated_at' => 'datetime',
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
     * @return BelongsTo<Step, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }
}
