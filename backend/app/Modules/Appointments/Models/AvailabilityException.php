<?php

namespace App\Modules\Appointments\Models;

use App\Modules\Appointments\Enums\ExceptionKind;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Exception aux plages hebdomadaires, à une date locale : fermeture (journée entière si
 * start_minute est null, sinon une plage) ou ouverture ponctuelle (plage).
 *
 * @property int $id
 * @property string $uuid
 * @property int $calendar_id
 * @property CarbonInterface $date
 * @property ExceptionKind $kind
 * @property int|null $start_minute
 * @property int|null $end_minute
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class AvailabilityException extends Model
{
    protected $table = 'appointment_availability_exceptions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'kind' => ExceptionKind::class,
            'start_minute' => 'integer',
            'end_minute' => 'integer',
        ];
    }

    public function isWholeDay(): bool
    {
        return $this->start_minute === null;
    }
}
