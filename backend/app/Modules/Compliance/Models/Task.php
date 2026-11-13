<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\DeadlineSource;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tâche à cocher : issue d'une règle (tâche d'une échéance ou étape d'un parcours, titre lu
 * dans la règle par template_key) ou ajoutée par l'utilisateur (titre chiffré en base).
 *
 * service_category_slug : catégorie de services de l'annuaire, quand la tâche demande un
 * professionnel ; le frontend propose alors des prestataires par GET /providers?category=…
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property int|null $deadline_id
 * @property int|null $journey_id
 * @property DeadlineSource $source
 * @property string|null $template_key
 * @property string|null $title
 * @property string|null $service_category_slug
 * @property int $position
 * @property CarbonInterface|null $due_on
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Task extends Model
{
    protected $table = 'compliance_tasks';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => DeadlineSource::class,
            'title' => 'encrypted',
            'position' => 'integer',
            'due_on' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Deadline, $this>
     */
    public function deadline(): BelongsTo
    {
        return $this->belongsTo(Deadline::class);
    }

    /**
     * @return BelongsTo<Journey, $this>
     */
    public function journey(): BelongsTo
    {
        return $this->belongsTo(Journey::class);
    }
}
