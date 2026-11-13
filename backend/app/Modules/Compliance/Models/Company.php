<?php

namespace App\Modules\Compliance\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Compliance\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Entreprise suivie par un prestataire ou un entrepreneur (profil du questionnaire).
 *
 * - Données strictement privées : seul le propriétaire y accède (CompanyPolicy, 404 sinon).
 * - Chiffrées en base (APP_KEY) : name, registration_number, vat_number.
 * - Aucun attribut assignable en masse : le profil passe par CompanyProfile (vérifié contre les
 *   règles vérifiées du pays), le reste par les Actions.
 * - legal_form_code : code d'une règle legal_form ; activity_types, vat_regime, options :
 *   vocabulaire fermé du module (enums).
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $name
 * @property string $country
 * @property string $legal_form_code
 * @property list<string> $activity_types
 * @property string $vat_regime
 * @property CarbonInterface $created_on
 * @property string $fiscal_year_end
 * @property array<string, bool|string> $options
 * @property string|null $registration_number
 * @property string|null $vat_number
 * @property CarbonInterface|null $calendar_refreshed_at
 * @property CarbonInterface|null $calendar_changed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[UseFactory(CompanyFactory::class)]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected $table = 'compliance_companies';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'activity_types' => 'array',
            'created_on' => 'date',
            'options' => 'array',
            'registration_number' => 'encrypted',
            'vat_number' => 'encrypted',
            'calendar_refreshed_at' => 'datetime',
            'calendar_changed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<Deadline, $this>
     */
    public function deadlines(): HasMany
    {
        return $this->hasMany(Deadline::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Journey, $this>
     */
    public function journeys(): HasMany
    {
        return $this->hasMany(Journey::class);
    }

    /**
     * Valeur d'une option du questionnaire (valeur par défaut si l'utilisateur n'a pas répondu).
     */
    public function option(string $option): bool|string|null
    {
        return $this->options[$option] ?? null;
    }
}
