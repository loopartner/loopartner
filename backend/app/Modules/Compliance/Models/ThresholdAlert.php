<?php

namespace App\Modules\Compliance\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerte de seuil : palier atteint par le chiffre d'affaires réel (kind = reached), ou
 * dépassement du seuil par la projection annuelle (kind = projected, level = 100). Une seule
 * fois par seuil, par année et par palier ; envoyée dans le récapitulatif par email.
 *
 * @property int $id
 * @property int $company_id
 * @property string $rule_code
 * @property int $year
 * @property string $kind
 * @property int $level
 * @property CarbonInterface $reached_at
 * @property CarbonInterface|null $notified_at
 */
class ThresholdAlert extends Model
{
    public const REACHED = 'reached';

    public const PROJECTED = 'projected';

    protected $table = 'compliance_threshold_alerts';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'level' => 'integer',
            'reached_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
