<?php

namespace App\Modules\Events\Support;

use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RevisionStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * File de modération : événements, révisions et annonces en attente, du plus ancien au plus
 * récent (date de soumission). Une seule requête UNION ALL, compatible MySQL, MariaDB et SQLite.
 */
final class ModerationQueue
{
    public const KINDS = ['event', 'revision', 'announcement'];

    /**
     * Éléments en attente : colonnes kind, uuid, submitted_at, event_id.
     */
    public static function query(?string $kind = null): Builder
    {
        $events = DB::table('events')
            ->selectRaw("'event' as kind, uuid, submitted_at, id as event_id")
            ->where('status', EventStatus::PendingReview->value);

        $revisions = DB::table('event_revisions')
            ->selectRaw("'revision' as kind, uuid, submitted_at, event_id")
            ->where('status', RevisionStatus::Pending->value);

        $announcements = DB::table('event_announcements')
            ->selectRaw("'announcement' as kind, uuid, created_at as submitted_at, event_id")
            ->where('status', AnnouncementStatus::PendingReview->value);

        return DB::query()
            ->fromSub($events->unionAll($revisions)->unionAll($announcements), 'queue')
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderBy('submitted_at')
            ->orderBy('uuid');
    }

    /**
     * Nombre d'éléments en attente par type.
     *
     * @return array{event: int, revision: int, announcement: int}
     */
    public static function counts(): array
    {
        $counts = DB::query()
            ->fromSub(self::query()->reorder(), 'pending')
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return [
            'event' => (int) ($counts['event'] ?? 0),
            'revision' => (int) ($counts['revision'] ?? 0),
            'announcement' => (int) ($counts['announcement'] ?? 0),
        ];
    }

    /**
     * Date de soumission de l'élément en attente le plus récent.
     */
    public static function newestSubmittedAt(): ?Carbon
    {
        $value = DB::query()->fromSub(self::query()->reorder(), 'pending')->max('submitted_at');

        return is_string($value) ? Carbon::parse($value, 'UTC') : null;
    }
}
