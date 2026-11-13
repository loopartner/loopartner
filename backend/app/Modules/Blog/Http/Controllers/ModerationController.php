<?php

namespace App\Modules\Blog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Blog\Actions\ApproveArticle;
use App\Modules\Blog\Actions\ApproveRevision;
use App\Modules\Blog\Actions\RejectArticle;
use App\Modules\Blog\Actions\RejectRevision;
use App\Modules\Blog\Actions\UnpublishArticle;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Http\Requests\ModerationDecisionRequest;
use App\Modules\Blog\Http\Requests\ModerationQueueRequest;
use App\Modules\Blog\Http\Resources\ModerationView;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRevision;
use App\Modules\Blog\Support\ModerationQueue;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Modération du Blog (/admin/blog/queue, /admin/articles/…, /admin/article-revisions/…).
 * Accès : rédacteurs et admins avec la double authentification de session (EnsureModerator) ;
 * 404 pour tout autre rôle. Personne ne modère son propre article (403).
 */
class ModerationController extends Controller
{
    private const ARTICLE_RELATIONS = ['user.roles', 'categories', 'cover', 'pendingRevision'];

    /**
     * File d'attente, du plus ancien au plus récent.
     */
    public function queue(ModerationQueueRequest $request): JsonResponse
    {
        $moderator = self::moderator($request);
        $kind = $request->validated('kind');
        $page = ModerationQueue::query(is_string($kind) ? $kind : null)
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $articles = Article::query()
            ->whereKey(collect($page->items())->pluck('article_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $items = collect($page->items())->map(function (object $item) use ($articles, $moderator): array {
            /** @var object{kind: string, uuid: string, submitted_at: string, article_id: int} $item */
            $article = $articles->get($item->article_id);

            return [
                'kind' => $item->kind,
                'uuid' => $item->uuid,
                'submitted_at' => Carbon::parse($item->submitted_at, 'UTC')->toIso8601ZuluString(),
                'own' => $article instanceof Article && $article->user_id === $moderator->id,
                'article' => $article instanceof Article ? [
                    'uuid' => $article->uuid,
                    'title' => $article->title,
                    'status' => $article->status->value,
                    'author_kind' => $article->author_kind->value,
                    'language' => $article->language,
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items->all(),
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => ModerationQueue::counts(),
            ],
        ]);
    }

    public function showArticle(Request $request, string $uuid): JsonResponse
    {
        return $this->articleResponse($request, $uuid);
    }

    public function approveArticle(ModerationDecisionRequest $request, string $uuid, ApproveArticle $approveArticle): JsonResponse
    {
        $article = $approveArticle->handle(self::moderator($request), self::article($uuid), $request->integer('version'));

        return $this->articleResponse($request, $article->uuid);
    }

    public function rejectArticle(ModerationDecisionRequest $request, string $uuid, RejectArticle $rejectArticle): JsonResponse
    {
        $article = $rejectArticle->handle(self::moderator($request), self::article($uuid), $request->integer('version'), $request->string('reason')->toString());

        return $this->articleResponse($request, $article->uuid);
    }

    public function unpublishArticle(ModerationDecisionRequest $request, string $uuid, UnpublishArticle $unpublishArticle): JsonResponse
    {
        $article = $unpublishArticle->handle(self::moderator($request), self::article($uuid), $request->string('reason')->toString());

        return $this->articleResponse($request, $article->uuid);
    }

    public function showRevision(Request $request, string $uuid): JsonResponse
    {
        return response()->json(['data' => ModerationView::revision(self::revision($uuid), self::moderator($request))]);
    }

    public function approveRevision(ModerationDecisionRequest $request, string $uuid, ApproveRevision $approveRevision): JsonResponse
    {
        $revision = $approveRevision->handle(self::moderator($request), self::revision($uuid), $request->integer('version'));

        return $this->showRevision($request, $revision->uuid);
    }

    public function rejectRevision(ModerationDecisionRequest $request, string $uuid, RejectRevision $rejectRevision): JsonResponse
    {
        $revision = $rejectRevision->handle(self::moderator($request), self::revision($uuid), $request->integer('version'), $request->string('reason')->toString());

        return $this->showRevision($request, $revision->uuid);
    }

    private function articleResponse(Request $request, string $uuid): JsonResponse
    {
        return response()->json(['data' => ModerationView::article(self::article($uuid)->load(self::ARTICLE_RELATIONS), self::moderator($request))]);
    }

    /**
     * Brouillons exclus : un modérateur ne lit pas un article que son auteur n'a jamais soumis.
     */
    private static function article(string $uuid): Article
    {
        $article = Article::query()->where('uuid', $uuid)->where('status', '!=', ArticleStatus::Draft->value)->first();
        abort_if($article === null, 404, 'Article introuvable.');

        return $article;
    }

    private static function revision(string $uuid): ArticleRevision
    {
        $revision = ArticleRevision::query()
            ->where('uuid', $uuid)
            ->with(['article.user.roles', 'article.categories', 'article.cover', 'cover'])
            ->first();
        abort_if($revision === null, 404, 'Modification introuvable.');

        return $revision;
    }

    private static function moderator(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
