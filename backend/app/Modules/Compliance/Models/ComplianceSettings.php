<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\ReminderFrequency;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Réglages du suivi administratif d'un utilisateur (une ligne au plus, créée à la première
 * modification ; ComplianceSettings::for() renvoie sinon les valeurs par défaut).
 *
 * Les réglages premium (plusieurs délais, paliers personnalisés) restent enregistrés après la
 * fin du premium, mais Entitlements les ramène aux valeurs gratuites à la lecture.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $emails_enabled
 * @property ReminderFrequency $reminder_frequency
 * @property list<int> $reminder_offsets
 * @property list<int> $threshold_levels
 * @property int|null $active_company_id
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class ComplianceSettings extends Model
{
    protected $table = 'compliance_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'emails_enabled' => 'boolean',
            'reminder_frequency' => ReminderFrequency::class,
            'reminder_offsets' => 'array',
            'threshold_levels' => 'array',
        ];
    }

    /**
     * Réglages de l'utilisateur, ou réglages par défaut non enregistrés.
     */
    public static function for(int $userId): self
    {
        $settings = self::query()->where('user_id', $userId)->first();

        if ($settings !== null) {
            return $settings;
        }

        $settings = new self;
        $settings->forceFill([
            'user_id' => $userId,
            'emails_enabled' => true,
            'reminder_frequency' => ReminderFrequency::Daily,
            'reminder_offsets' => array_map('intval', (array) config('compliance.reminders.default_offsets')),
            'threshold_levels' => array_map('intval', (array) config('compliance.thresholds.default_levels')),
            'active_company_id' => null,
        ]);

        return $settings;
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function activeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'active_company_id');
    }
}
