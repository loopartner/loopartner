<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Support\EncryptedInteger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Chiffre d'affaires d'un mois pour un type d'activité, en centimes (entier chiffré en base).
 *
 * @property int $id
 * @property int $company_id
 * @property CarbonInterface $period
 * @property string $activity_type
 * @property int $amount
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class RevenueEntry extends Model
{
    protected $table = 'compliance_revenue_entries';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => EncryptedInteger::class,
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
