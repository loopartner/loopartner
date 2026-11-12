<?php

namespace App\Modules\Events\Models;

use App\Modules\Events\Enums\AnnouncementStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Annonce d'un organisateur à ses inscrits. Son texte n'est lisible que sur la page de
 * l'événement (inscrits connectés) : l'email de notification ne le contient pas. Seul le texte
 * est assignable en masse.
 *
 * @property int $id
 * @property string $uuid
 * @property int $event_id
 * @property int $user_id
 * @property string $body
 * @property bool $has_links
 * @property AnnouncementStatus $status
 * @property int $recipients_count
 * @property Carbon|CarbonImmutable|null $published_at
 * @property Carbon|CarbonImmutable|null $decided_at
 * @property int|null $decided_by
 * @property string|null $rejection_reason
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Event $event
 */
#[Fillable(['body'])]
class EventAnnouncement extends Model
{
    public const BODY_MIN_LENGTH = 20;

    public const BODY_MAX_LENGTH = 2000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AnnouncementStatus::class,
            'has_links' => 'boolean',
            'recipients_count' => 'integer',
            'published_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
