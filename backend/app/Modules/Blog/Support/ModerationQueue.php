<?php

namespace App\Modules\Blog\Support;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\RevisionStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * File de modération du Blog : articles et révisions en attente, du plus ancien au plus récent
 * (date de soumission). Une seule requête UNION ALL, compatible MySQL, MariaDB et SQLite.
 */
final class ModerationQueue
{
    public const KINDS = ['article', 'revision'];

    /**
     * Éléments en attente : colonnes kind, uuid, submitted_at, article_id.
     */
    public static function query(?string $kind = null): Builder
    {
        $articles = DB::table('articles')
            ->selectRaw("'article' as kind, uuid, submitted_at, id as article_id")
            ->where('status', ArticleStatus::PendingReview->value);

        $revisions = DB::table('article_revisions')
            ->selectRaw("'revision' as kind, uuid, submitted_at, article_id")
            ->where('status', RevisionStatus::Pending->value);

        return DB::query()
            ->fromSub($articles->unionAll($revisions), 'queue')
            ->when($kind !== null, fn (Builder $query) => $query->where('kind', $kind))
            ->orderBy('submitted_at')
            ->orderBy('uuid');
    }

    /**
     * @return array{article: int, revision: int}
     */
    public static function counts(): array
    {
        $counts = DB::query()
            ->fromSub(self::query()->reorder(), 'pending')
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return [
            'article' => (int) ($counts['article'] ?? 0),
            'revision' => (int) ($counts['revision'] ?? 0),
        ];
    }

    public static function newestSubmittedAt(): ?Carbon
    {
        $value = DB::query()->fromSub(self::query()->reorder(), 'pending')->max('submitted_at');

        return is_string($value) ? Carbon::parse($value, 'UTC') : null;
    }
}
