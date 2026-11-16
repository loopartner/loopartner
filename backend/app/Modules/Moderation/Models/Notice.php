<?php

namespace App\Modules\Moderation\Models;

use App\Models\User;
use App\Modules\Moderation\Enums\NoticePriority;
use App\Modules\Moderation\Enums\NoticeSource;
use App\Modules\Moderation\Enums\NoticeStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Moderation\NoticeFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Signalement d'un contenu (DSA art. 16), par le formulaire public ou depuis la messagerie.
 * Privé au module Moderation : les autres modules passent par ses Actions publiques
 * (SubmitNotice, CountNoticesAgainst).
 *
 * Aucun attribut n'est assignable en masse : tout est fixé par les Actions. L'identité du
 * signalant, son explication et la copie du contenu sont chiffrées en base, jamais exposées
 * au propriétaire du contenu, et effacées après security.retention.moderation_notices_months.
 * La copie du contenu est uniquement textuelle (aucune image n'est copiée).
 *
 * @property int $id
 * @property string $uuid
 * @property string $target_type
 * @property string $target_ref
 * @property int|null $target_owner_id
 * @property string $category
 * @property NoticePriority $priority
 * @property NoticeSource $source
 * @property string|null $source_reason
 * @property string|null $explanation
 * @property string|null $location_detail
 * @property string|null $content_label
 * @property string|null $content_snapshot
 * @property string|null $content_version
 * @property int|null $reporter_id
 * @property string|null $reporter_name
 * @property string|null $reporter_email
 * @property string|null $reporter_email_hash
 * @property string|null $reporter_locale
 * @property string|null $tracking_token_hash
 * @property string|null $active_key
 * @property Carbon|CarbonImmutable|null $good_faith_confirmed_at
 * @property NoticeStatus $status
 * @property int|null $decision_id
 * @property bool $manifestly_unfounded
 * @property Carbon|CarbonImmutable $due_at
 * @property Carbon|CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property Carbon|CarbonImmutable|null $urgent_alert_sent_at
 * @property Carbon|CarbonImmutable|null $referred_at
 * @property int|null $referred_by
 * @property Carbon|CarbonImmutable|null $personal_data_purged_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Decision|null $decision
 */
#[UseFactory(NoticeFactory::class)]
class Notice extends Model
{
    /** @use HasFactory<NoticeFactory> */
    use HasFactory;

    protected $table = 'moderation_notices';

    /**
     * @var list<string>
     */
    protected $hidden = ['reporter_email', 'reporter_email_hash', 'tracking_token_hash', 'active_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => NoticePriority::class,
            'source' => NoticeSource::class,
            'status' => NoticeStatus::class,
            'explanation' => 'encrypted',
            'location_detail' => 'encrypted',
            'content_label' => 'encrypted',
            'content_snapshot' => 'encrypted',
            'reporter_name' => 'encrypted',
            'reporter_email' => 'encrypted',
            'manifestly_unfounded' => 'boolean',
            'good_faith_confirmed_at' => 'datetime',
            'due_at' => 'datetime',
            'closed_at' => 'datetime',
            'urgent_alert_sent_at' => 'datetime',
            'referred_at' => 'datetime',
            'personal_data_purged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function isOpen(): bool
    {
        return $this->status === NoticeStatus::Open;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_at->isPast();
    }
}
