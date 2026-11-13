<?php

namespace App\Modules\Compliance\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Parcours suivi par une entreprise (« créer mon entreprise »…), tiré d'une règle journey
 * vérifiée : ses étapes sont des tâches. Terminé quand toutes ses tâches sont cochées.
 *
 * @property int $id
 * @property string $uuid
 * @property int $company_id
 * @property string $rule_code
 * @property int|null $rule_id
 * @property CarbonInterface $started_at
 * @property CarbonInterface|null $completed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Journey extends Model
{
    protected $table = 'compliance_journeys';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
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
}
