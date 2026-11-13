<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\DeadlineSource;
use App\Modules\Compliance\Enums\DeadlineStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Échéance du calendrier d'une entreprise : issue d'une règle vérifiée (source rule, titre lu
 * dans la règle, recalculée) ou personnelle (source custom, titre saisi, jamais recalculée).
 *
 * Chiffrés en base : title (échéance personnelle) et notes. Une échéance faite ou ignorée n'est
 * jamais modifiée par un recalcul ; si sa règle ne s'applique plus, obsolete_at est rempli.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property DeadlineSource $source
 * @property string|null $rule_code
 * @property string|null $occurrence_key
 * @property int|null $rule_id
 * @property string|null $title
 * @property CarbonInterface $due_on
 * @property DeadlineStatus $status
 * @property CarbonInterface|null $completed_at
 * @property string|null $notes
 * @property CarbonInterface|null $obsolete_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Deadline extends Model
{
    protected $table = 'compliance_deadlines';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'todo',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source' => DeadlineSource::class,
            'status' => DeadlineStatus::class,
            'title' => 'encrypted',
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'notes' => 'encrypted',
            'obsolete_at' => 'datetime',
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
     * Version de la règle qui a produit l'échéance (titre, résumé, source officielle).
     *
     * @return BelongsTo<ComplianceRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ComplianceRule::class, 'rule_id');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position')->orderBy('id');
    }

    public function isFromRule(): bool
    {
        return $this->source === DeadlineSource::Rule;
    }
}
