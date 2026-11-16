<?php

namespace App\Modules\Directory\Models;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Directory\Enums\ProjectStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Directory\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Projet d'un particulier ou d'un entrepreneur.
 *
 * - uuid est le seul identifiant exposé par l'API.
 * - Seuls les champs de contenu sont assignables en masse. uuid, user_id, service_category_id,
 *   status et les dates du cycle de vie sont fixés par les Actions du module.
 * - Un projet publié est visible des prestataires jusqu'à expires_at (60 jours après la
 *   publication), même avant que la tâche quotidienne ne le clôture.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int $service_category_id
 * @property string $title
 * @property string $description
 * @property int|null $budget_min
 * @property int|null $budget_max
 * @property string $currency
 * @property string $country
 * @property string|null $city
 * @property bool $remote_ok
 * @property Carbon|CarbonImmutable|null $desired_start_date
 * @property ProjectStatus $status
 * @property Carbon|CarbonImmutable|null $published_at
 * @property Carbon|CarbonImmutable|null $expires_at
 * @property Carbon|CarbonImmutable|null $closed_at
 * @property Carbon|CarbonImmutable|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[UseFactory(ProjectFactory::class)]
#[Fillable(['title', 'description', 'budget_min', 'budget_max', 'currency', 'country', 'city', 'remote_ok', 'desired_start_date'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * Nombre maximal de projets publiés (et non expirés) en même temps par utilisateur.
     */
    public const MAX_PUBLISHED_PER_USER = 10;

    /**
     * Durée de publication, en jours.
     */
    public const PUBLICATION_DAYS = 60;

    public const DEFAULT_CURRENCY = 'EUR';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'currency' => self::DEFAULT_CURRENCY,
        'remote_ok' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_restricted_at' => 'datetime',
            'status' => ProjectStatus::class,
            'budget_min' => 'integer',
            'budget_max' => 'integer',
            'remote_ok' => 'boolean',
            'desired_start_date' => 'date',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
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
     * Catégorie de services (modèle du noyau).
     *
     * @return BelongsTo<ServiceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    /**
     * Publié et pas encore expiré : visible des prestataires et compté dans le plafond.
     */
    public function isLive(): bool
    {
        return $this->status === ProjectStatus::Published
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * Projets publiés et non expirés.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', ProjectStatus::Published->value)
            ->where('expires_at', '>', now());
    }

    /**
     * Projets visibles des prestataires : en ligne (live) et non masqués par la modération.
     * Un projet masqué compte toujours dans le plafond de projets publiés de son porteur.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function browsable(Builder $query): void
    {
        $query->live()->whereNull('moderation_restricted_at');
    }
}
