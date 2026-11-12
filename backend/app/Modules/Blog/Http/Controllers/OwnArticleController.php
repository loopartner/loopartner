<?php

namespace App\Modules\Blog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Blog\Actions\CreateArticle;
use App\Modules\Blog\Actions\DeleteArticle;
use App\Modules\Blog\Actions\GetArticleQuota;
use App\Modules\Blog\Actions\SubmitArticle;
use App\Modules\Blog\Actions\UpdateArticle;
use App\Modules\Blog\Actions\WithdrawRevision;
use App\Modules\Blog\Actions\WithdrawSubmission;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Http\Requests\ListOwnArticlesRequest;
use App\Modules\Blog\Http\Requests\StoreArticleRequest;
use App\Modules\Blog\Http\Requests\UpdateArticleRequest;
use App\Modules\Blog\Http\Resources\OwnArticleResource;
use App\Modules\Blog\Models\Article;
use App\Support\Pagination;
use App\Support\TwoFactorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Articles de l'auteur connecté (/me/articles). L'article d'un autre utilisateur donne 404
 * (ArticlePolicy), modérateurs compris.
 */
class OwnArticleController extends Controller
{
    private const RELATIONS = ['categories', 'cover', 'pendingRevision.cover', 'latestRevision'];

    public function index(ListOwnArticlesRequest $request, GetArticleQuota $quota): AnonymousResourceCollection
    {
        $user = self::user($request);

        $articles = Article::query()
            ->where('user_id', $user->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with(self::RELATIONS)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return OwnArticleResource::collection($articles)->additional(['meta' => ['quota' => $quota->handle($user)]]);
    }

    /**
     * Quota gratuit : {premium, limit, used, remaining} pour un prestataire ou un entrepreneur,
     * null pour l'équipe.
     */
    public function quota(Request $request, GetArticleQuota $quota): JsonResponse
    {
        Gate::authorize('viewAnyOwn', Article::class);

        return response()->json(['data' => $quota->handle(self::user($request))]);
    }

    public function store(StoreArticleRequest $request, CreateArticle $createArticle): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $article = $createArticle->handle(self::user($request), $data);

        return OwnArticleResource::detailed($article->load(self::RELATIONS))->response()->setStatusCode(201);
    }

    public function show(string $uuid): OwnArticleResource
    {
        $article = self::find($uuid);
        Gate::authorize('view', $article);

        return OwnArticleResource::detailed($article->load(self::RELATIONS));
    }

    /**
     * Brouillon, refusé ou dépublié : modification directe (200). Publié : révision en attente
     * de validation (202), la version publique ne change pas. Article publié de l'équipe :
     * modification directe avec la 2FA de session (403 sans).
     */
    public function update(UpdateArticleRequest $request, UpdateArticle $updateArticle): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();
        $article = $request->article();

        if ($article->author_kind->isEditorial() && $article->status === ArticleStatus::Published && ! TwoFactorSession::passed($request)) {
            abort(403, 'La modification d\'un article publié de la rédaction exige la double authentification : activez-la, puis reconnectez-vous avec votre code.');
        }

        ['article' => $article, 'revision' => $revision] = $updateArticle->handle(self::user($request), $article, $data);

        return OwnArticleResource::detailed($article->load(self::RELATIONS))
            ->response()
            ->setStatusCode($revision !== null ? 202 : 200);
    }

    public function destroy(string $uuid, DeleteArticle $deleteArticle): Response
    {
        $article = self::find($uuid);
        Gate::authorize('delete', $article);

        $deleteArticle->handle($article);

        return response()->noContent();
    }

    /**
     * Soumission à la validation (202), ou publication directe d'un article de l'équipe (200),
     * qui exige la 2FA de session (403 sans) : un compte de l'équipe ne publie jamais sans
     * cette protection.
     */
    public function submit(Request $request, string $uuid, SubmitArticle $submitArticle): JsonResponse
    {
        $article = self::find($uuid);
        Gate::authorize('submit', $article);

        if ($article->author_kind->isEditorial() && ! TwoFactorSession::passed($request)) {
            abort(403, 'La publication d\'un article de la rédaction exige la double authentification : activez-la, puis reconnectez-vous avec votre code.');
        }

        $article = $submitArticle->handle(self::user($request), $article);

        return OwnArticleResource::detailed($article->load(self::RELATIONS))
            ->response()
            ->setStatusCode($article->isPublished() ? 200 : 202);
    }

    public function withdraw(string $uuid, WithdrawSubmission $withdrawSubmission): OwnArticleResource
    {
        $article = self::find($uuid);
        Gate::authorize('update', $article);

        return OwnArticleResource::detailed($withdrawSubmission->handle($article)->load(self::RELATIONS));
    }

    public function withdrawRevision(string $uuid, WithdrawRevision $withdrawRevision): Response
    {
        $article = self::find($uuid);
        Gate::authorize('update', $article);

        $withdrawRevision->handle($article);

        return response()->noContent();
    }

    /**
     * abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
     */
    public static function find(string $uuid): Article
    {
        $article = Article::query()->where('uuid', $uuid)->first();
        abort_if($article === null, 404, 'Article introuvable.');

        return $article;
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
