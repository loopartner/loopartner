<?php

namespace App\Modules\Blog\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Accounts\Support\EntitlementSet;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Exceptions\PremiumRequired;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleQuotaEntry;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Quota et plafonds d'articles par auteur.
 *
 * - Quota gratuit (config blog.free_articles_quota) : chaque article d'un prestataire ou d'un
 *   entrepreneur compte à sa PREMIÈRE soumission, une fois pour toutes (table
 *   article_quota_entries) : le resoumettre après un refus ne compte pas de nouveau, et le
 *   supprimer ne libère rien. Les soumissions faites pendant une période premium comptent
 *   aussi. Le quota vient des droits de l'auteur (Action publique GetEntitlements du module
 *   Accounts, fonctionnalité blog.article_quota : limite du plan gratuit, illimitée avec
 *   premium). Au-delà : 403 "premium_required".
 * - Plafonds pour tous les auteurs extérieurs : articles en attente (max_pending_per_author),
 *   nouvelles soumissions d'un même article (max_resubmissions) ; pour tous : brouillons
 *   (max_drafts_per_author). Au-delà : 409.
 *
 * À appeler dans une transaction, après lock() : la ligne users de l'auteur est verrouillée
 * pendant le comptage, deux soumissions simultanées ne dépassent pas les limites.
 */
final class ArticleQuota
{
    /** Fonctionnalité de config/entitlements.php (Action publique GetEntitlements). */
    public const FEATURE = 'blog.article_quota';

    public static function lock(int $userId): void
    {
        User::query()->whereKey($userId)->lockForUpdate()->first();
    }

    /**
     * État du quota d'un auteur extérieur.
     *
     * @return array{premium: bool, limit: int, used: int, remaining: int}
     */
    public static function status(int $userId): array
    {
        return self::statusFrom(app(GetEntitlements::class)->handle($userId), $userId);
    }

    /**
     * La limite affichée est toujours celle du plan gratuit du rôle (un abonné premium voit
     * aussi son quota gratuit, qui reprend effet à la fin du premium).
     *
     * @return array{premium: bool, limit: int, used: int, remaining: int}
     */
    private static function statusFrom(EntitlementSet $entitlements, int $userId): array
    {
        $free = app(GetEntitlements::class)->features($entitlements->role, EntitlementSet::PLAN_FREE);
        $limit = (int) ($free[self::FEATURE] ?? 0);
        $used = self::used($userId);

        return [
            'premium' => $entitlements->isPremium(),
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
        ];
    }

    public static function used(int $userId): int
    {
        $months = config('blog.free_quota_period_months');

        return ArticleQuotaEntry::query()
            ->where('user_id', $userId)
            ->when(is_numeric($months), fn ($query) => $query->where('counted_at', '>', now()->subMonths((int) $months)))
            ->count();
    }

    /**
     * Vérifie qu'un auteur extérieur peut soumettre cet article, et compte sa première
     * soumission dans le quota.
     *
     * @throws PremiumRequired
     * @throws ConflictHttpException
     */
    public static function consumeForSubmission(User $author, Article $article): void
    {
        $maxSubmissions = 1 + (int) config('blog.max_resubmissions');

        if ($article->submission_count >= $maxSubmissions) {
            throw new ConflictHttpException(sprintf(
                'Cet article a déjà été soumis %d fois : il ne peut plus être soumis de nouveau.',
                $maxSubmissions,
            ));
        }

        $maxPending = (int) config('blog.max_pending_per_author');
        $pending = Article::query()
            ->where('user_id', $author->id)
            ->where('status', ArticleStatus::PendingReview->value)
            ->count();

        if ($pending >= $maxPending) {
            SecurityLog::record('blog.pending_limit_reached', ['user_id' => $author->id], 'warning');

            throw new ConflictHttpException(sprintf(
                'Vous avez déjà %d articles en attente de validation : attendez la décision de l\'équipe avant d\'en soumettre un autre.',
                $maxPending,
            ));
        }

        $alreadyCounted = ArticleQuotaEntry::query()
            ->where('user_id', $author->id)
            ->where('article_uuid', $article->uuid)
            ->exists();

        if ($alreadyCounted) {
            return;
        }

        $entitlements = app(GetEntitlements::class)->handle($author);
        $status = self::statusFrom($entitlements, $author->id);
        $quota = $entitlements->limit(self::FEATURE);

        if ($quota !== null && $status['used'] >= $quota) {
            SecurityLog::record('blog.quota_reached', ['user_id' => $author->id, 'article' => $article->uuid]);

            throw new PremiumRequired($status['limit'], $status['used']);
        }

        $entry = new ArticleQuotaEntry;
        $entry->forceFill([
            'user_id' => $author->id,
            'article_uuid' => $article->uuid,
            'counted_at' => now(),
        ])->save();
    }

    /**
     * @throws ConflictHttpException
     */
    public static function assertCanCreateDraft(int $userId): void
    {
        $max = (int) config('blog.max_drafts_per_author');

        $drafts = Article::query()
            ->where('user_id', $userId)
            ->where('status', ArticleStatus::Draft->value)
            ->count();

        if ($drafts >= $max) {
            throw new ConflictHttpException(sprintf(
                'Vous avez déjà %d brouillons : terminez-en ou supprimez-en un avant d\'en commencer un autre.',
                $max,
            ));
        }
    }
}
