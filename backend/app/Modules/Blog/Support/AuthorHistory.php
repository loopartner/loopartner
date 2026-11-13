<?php

namespace App\Modules\Blog\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Accounts\Actions\IsPremium;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Enums\RevisionStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Directory\Actions\GetUserModerationSignals;

/**
 * Historique de l'auteur présenté aux modérateurs : ancienneté du compte, type de compte,
 * statut premium (Action publique IsPremium), profil public (prestataire), articles par statut,
 * révisions refusées, et signaux de la messagerie (signalements reçus, blocages subis) via
 * l'Action publique du module Directory.
 *
 * Que des nombres et le profil public : ni email, ni nom d'utilisateur, ni identifiant interne.
 */
class AuthorHistory
{
    public function __construct(
        private GetProviderSummaries $getProviderSummaries,
        private GetUserModerationSignals $getUserModerationSignals,
        private IsPremium $isPremium,
    ) {}

    /**
     * @return array<string, mixed>|null null si le compte a été supprimé
     */
    public function for(?User $author): ?array
    {
        if ($author === null) {
            return null;
        }

        $articles = Article::query()
            ->where('user_id', $author->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $revisions = ArticleRevision::query()
            ->where('user_id', $author->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn ($counts, string $status): int => (int) ($counts[$status] ?? 0);
        $isProvider = Authors::kindOf($author) === AuthorKind::Provider;

        return [
            'account' => [
                'account_type' => Authors::accountType($author),
                'age_days' => (int) $author->created_at?->diffInDays(now()),
                'email_verified' => $author->hasVerifiedEmail(),
                'premium' => $this->isPremium->handle($author->id),
            ],
            'provider' => $isProvider ? ($this->getProviderSummaries->handle([$author->id])[$author->id] ?? null) : null,
            'articles' => [
                'draft' => $count($articles, ArticleStatus::Draft->value),
                'pending_review' => $count($articles, ArticleStatus::PendingReview->value),
                'published' => $count($articles, ArticleStatus::Published->value),
                'rejected' => $count($articles, ArticleStatus::Rejected->value),
                'unpublished' => $count($articles, ArticleStatus::Unpublished->value),
                'free_quota_used' => ArticleQuota::used($author->id),
            ],
            'revisions' => [
                'approved' => $count($revisions, RevisionStatus::Approved->value),
                'rejected' => $count($revisions, RevisionStatus::Rejected->value),
            ],
            'messaging' => $this->getUserModerationSignals->handle($author->id),
        ];
    }
}
