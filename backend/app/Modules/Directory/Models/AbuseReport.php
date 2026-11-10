<?php

namespace App\Modules\Directory\Models;

use App\Modules\Directory\Enums\ReportReason;
use App\Modules\Directory\Enums\ReportStatus;
use App\Modules\Directory\Enums\ReportTarget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Signalement d'un message ou d'une demande, conservé pour la modération (back-office à venir).
 *
 * content_snapshot copie le contenu signalé au moment du signalement : la modération peut juger
 * même si ce contenu disparaît ensuite. Cette copie et le commentaire sont effacés après la
 * durée de conservation (PruneAbuseReports). Seuls le motif et le commentaire sont assignables.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $reporter_id
 * @property int|null $reported_user_id
 * @property ReportTarget $target_type
 * @property int $target_id
 * @property ReportReason $reason
 * @property string|null $comment
 * @property string|null $content_snapshot
 * @property ReportStatus $status
 * @property Carbon|CarbonImmutable|null $closed_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[Fillable(['reason', 'comment'])]
class AbuseReport extends Model
{
    public const COMMENT_MAX_LENGTH = 1000;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'target_type' => ReportTarget::class,
            'reason' => ReportReason::class,
            'status' => ReportStatus::class,
            'target_id' => 'integer',
            'closed_at' => 'datetime',
        ];
    }
}
