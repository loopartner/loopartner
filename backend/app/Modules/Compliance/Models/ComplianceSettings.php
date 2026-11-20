<?php

namespace App\Modules\Compliance\Models;

use App\Models\User;
use App\Modules\Compliance\Enums\ReminderFrequency;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
     * Seul point d'écriture des réglages : applique $change aux réglages de l'utilisateur
     * (créés au besoin), puis les enregistre.
     *
     * Les écritures d'un utilisateur passent l'une après l'autre (verrou de sa ligne users) :
     * sans lui, deux premières écritures simultanées (réglages, entreprise active) inséraient
     * chacune la ligne et la seconde heurtait l'index unique user_id ; deux modifications
     * partielles se lisaient aussi l'une l'autre avant écriture. Si l'index refuse malgré tout
     * l'insertion, la seconde tentative modifie la ligne créée entre-temps.
     *
     * @param  Closure(self): void  $change
     */
    public static function change(int $userId, Closure $change): self
    {
        return retry(2, fn (): self => DB::transaction(function () use ($userId, $change): self {
            User::query()->whereKey($userId)->lockForUpdate()->first();

            $settings = self::for($userId);
            $change($settings);
            $settings->save();

            return $settings;
        }), 0, fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function activeCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'active_company_id');
    }
}
