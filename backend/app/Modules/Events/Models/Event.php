<?php

namespace App\Modules\Events\Models;

use App\Models\Media;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventFormat;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventType;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Enums\RevisionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Events\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Événement de l'agenda.
 *
 * - uuid identifie l'événement pour son organisateur et les admins ; le public ne voit que le
 *   slug, fixé à la première validation et jamais modifié.
 * - Seuls les champs de contenu sont assignables en masse. uuid, slug, user_id, status,
 *   version, cover_media_id, les compteurs et les dates du cycle de vie sont fixés par les
 *   Actions du module.
 * - online_url (lien de connexion) est chiffré en base et n'est jamais exposé au public.
 * - Un événement publié est visible de tous jusqu'à ends_at, même avant que la tâche
 *   events:close-past ne le passe au statut "past".
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $slug
 * @property int|null $user_id
 * @property EventStatus $status
 * @property int $version
 * @property EventType $type
 * @property EventFormat $format
 * @property string $title
 * @property string $description
 * @property string|null $organizer_name
 * @property string $language
 * @property string|null $venue_name
 * @property string|null $address_line
 * @property string|null $postal_code
 * @property string|null $city
 * @property string|null $country
 * @property AddressVisibility $address_visibility
 * @property string|null $online_url
 * @property string|null $external_url
 * @property bool $is_free
 * @property string|null $ticket_url
 * @property Carbon|CarbonImmutable $starts_at
 * @property Carbon|CarbonImmutable $ends_at
 * @property string $timezone
 * @property int|null $capacity
 * @property int $registrations_count
 * @property int|null $cover_media_id
 * @property Carbon|CarbonImmutable|null $submitted_at
 * @property Carbon|CarbonImmutable|null $published_at
 * @property Carbon|CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|CarbonImmutable|null $cancelled_at
 * @property CancelledBy|null $cancelled_by
 * @property string|null $cancellation_reason
 * @property Carbon|CarbonImmutable|null $reminder_sent_at
 * @property Carbon|CarbonImmutable|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[UseFactory(EventFactory::class)]
#[Fillable([
    'type', 'format', 'title', 'description', 'organizer_name', 'language', 'venue_name',
    'address_line', 'postal_code', 'city', 'country', 'address_visibility', 'online_url',
    'external_url', 'is_free', 'ticket_url', 'starts_at', 'ends_at', 'timezone', 'capacity',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
        'address_visibility' => 'public',
        'is_free' => true,
        'registrations_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_restricted_at' => 'datetime',
            'status' => EventStatus::class,
            'type' => EventType::class,
            'format' => EventFormat::class,
            'address_visibility' => AddressVisibility::class,
            'cancelled_by' => CancelledBy::class,
            'online_url' => 'encrypted',
            'is_free' => 'boolean',
            'version' => 'integer',
            'capacity' => 'integer',
            'registrations_count' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Organisateur (null si son compte a été supprimé).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * Catégories de services (modèle du noyau).
     *
     * @return BelongsToMany<ServiceCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'event_category')
            ->orderBy('service_categories.position')
            ->orderBy('service_categories.slug');
    }

    /**
     * @return HasMany<EventRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(EventRevision::class);
    }

    /**
     * Révision en attente de validation (au plus une).
     *
     * @return HasOne<EventRevision, $this>
     */
    public function pendingRevision(): HasOne
    {
        return $this->hasOne(EventRevision::class)->where('status', RevisionStatus::Pending->value);
    }

    /**
     * Dernière révision, quel que soit son statut (pour afficher un refus à l'organisateur).
     *
     * @return HasOne<EventRevision, $this>
     */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(EventRevision::class)->latestOfMany();
    }

    /**
     * @return HasMany<EventRegistration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * @return HasMany<EventAnnouncement, $this>
     */
    public function announcements(): HasMany
    {
        return $this->hasMany(EventAnnouncement::class)->orderBy('id');
    }

    /**
     * Publié et pas encore terminé : visible dans l'agenda public.
     */
    public function isLive(): bool
    {
        return $this->status === EventStatus::Published && $this->ends_at->isFuture();
    }

    /**
     * Publié et pas encore commencé : modifiable par révision, ouvert aux inscriptions.
     */
    /**
     * Masqué par la modération (module Moderation) : absent de l'agenda public, inscriptions,
     * rappels et annonces fermés, jusqu'à la levée de la restriction.
     */
    public function isRestricted(): bool
    {
        return $this->moderation_restricted_at !== null;
    }

    public function isUpcoming(): bool
    {
        return $this->status === EventStatus::Published && $this->starts_at->isFuture();
    }

    /**
     * Terminé (statut "past", ou publié et dont la date de fin est passée).
     */
    public function hasEnded(): bool
    {
        return $this->status === EventStatus::Past
            || ($this->status === EventStatus::Published && ! $this->ends_at->isFuture());
    }

    /**
     * Nombre maximal d'inscrits : la capacité, plafonnée par events.max_registrations.
     */
    public function registrationLimit(): int
    {
        $max = (int) config('events.max_registrations');

        return $this->capacity === null ? $max : min($this->capacity, $max);
    }

    public function isFull(): bool
    {
        return $this->registrations_count >= $this->registrationLimit();
    }

    /**
     * Inscriptions internes possibles : événement gratuit, publié, pas commencé, pas complet.
     */
    public function acceptsRegistrations(): bool
    {
        return $this->is_free && $this->isUpcoming() && ! $this->isFull();
    }

    /**
     * Inscription active d'un utilisateur, ou null.
     */
    public function registrationOf(User $user): ?EventRegistration
    {
        return $this->registrations()
            ->where('user_id', $user->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->first();
    }

    /**
     * Événements publiés et pas encore terminés (agenda public).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', EventStatus::Published->value)->where('ends_at', '>', now());
    }

    /**
     * Événements visibles par le public en détail : publiés (à venir, en cours ou terminés en
     * attente de la tâche planifiée) et passés.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function publiclyVisible(Builder $query): void
    {
        $query->whereIn('status', [EventStatus::Published->value, EventStatus::Past->value]);
    }

    /**
     * Événements non masqués par la modération (à combiner avec live() ou publiclyVisible()
     * pour l'agenda public).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function notRestricted(Builder $query): void
    {
        $query->whereNull('moderation_restricted_at');
    }
}
