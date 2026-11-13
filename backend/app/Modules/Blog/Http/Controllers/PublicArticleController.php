<?php

namespace App\Modules\Blog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Accounts\Actions\FindPublishedProvider;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Http\Requests\SearchArticlesRequest;
use App\Modules\Blog\Http\Resources\PublicArticleResource;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Models\ArticleRetiredSlug;
use App\Modules\Blog\Support\AuthorDirectory;
use App\Support\Database\Like;
use App\Support\Locale;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Lecture publique du blog, sans authentification : uniquement les articles publiés (validés
 * par l'équipe, ou publiés par l'équipe elle-même).
 */
class PublicArticleController extends Controller
{
    private const RELATIONS = ['categories', 'cover'];

    /**
     * Liste paginée, de la publication la plus récente à la plus ancienne.
     */
    public function index(SearchArticlesRequest $request): AnonymousResourceCollection
    {
        $query = Article::query()->published();

        if ($request->filled('category')) {
            $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn(
                'service_categories.id',
                ServiceCategory::idsWithDescendants($request->string('category')->toString()),
            ));
        }

        if ($request->filled('language')) {
            $query->where('language', $request->string('language')->toString());
        }

        if ($request->filled('author')) {
            $this->filterAuthor($query, $request->string('author')->toString());
        }

        if ($request->filled('q')) {
            Like::whereContains($query->getQuery(), ['title', 'excerpt'], $request->string('q')->toString());
        }

        return $this->page($request, $query);
    }

    /**
     * Articles d'un prestataire, pour sa page publique (404 si son profil n'est pas publié).
     */
    public function provider(Request $request, string $slug, FindPublishedProvider $findProvider): AnonymousResourceCollection
    {
        $request->validate(Pagination::rules());

        $provider = $findProvider->handle($slug);
        abort_if($provider === null, 404, 'Prestataire introuvable.');

        $query = Article::query()
            ->published()
            ->where('user_id', $provider['user_id'])
            ->where('author_kind', AuthorKind::Provider->value);

        return $this->page($request, $query);
    }

    /**
     * Détail d'un article publié, avec des articles liés (même langue, catégorie commune).
     * Slug d'un article supprimé : 410, pour que les moteurs de recherche l'oublient.
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $article = Article::query()->published()->where('slug', $slug)->with(self::RELATIONS)->first();

        if ($article === null) {
            abort_if(ArticleRetiredSlug::query()->where('slug', $slug)->exists(), 410, 'Cet article a été supprimé.');
            abort(404, 'Article introuvable.');
        }

        $related = $this->related($article);
        $authors = AuthorDirectory::for([$article, ...$related], Locale::fromRequest($request));

        return response()->json([
            'data' => [
                ...PublicArticleResource::detailed($article, $authors)->resolve($request),
                'related' => $related->map(fn (Article $item): array => PublicArticleResource::summary($item, $authors)->resolve($request))->values()->all(),
            ],
        ])->header('Vary', 'Accept-Language');
    }

    /**
     * Articles publiés pour le sitemap du frontend : slug, langue, date de dernière mise à jour.
     * Pages de config('blog.sitemap_per_page') articles.
     */
    public function sitemap(Request $request): JsonResponse
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1', 'max:10000']]);

        $page = Article::query()
            ->published()
            ->orderBy('id')
            ->paginate((int) config('blog.sitemap_per_page'), ['slug', 'language', 'published_at', 'content_updated_at']);

        return response()->json([
            'data' => collect($page->items())->map(fn (Article $article): array => [
                'slug' => $article->slug,
                'language' => $article->language,
                'updated_at' => ($article->content_updated_at ?? $article->published_at)?->toIso8601ZuluString(),
            ])->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ])->header('Cache-Control', 'public, max-age=3600');
    }

    /**
     * @param  Builder<Article>  $query
     */
    private function filterAuthor(Builder $query, string $author): void
    {
        if ($author === SearchArticlesRequest::EDITORIAL) {
            $query->where('author_kind', AuthorKind::Editorial->value);

            return;
        }

        $provider = app(FindPublishedProvider::class)->handle($author);

        // Prestataire inconnu ou dépublié : aucun résultat (pas d'erreur qui confirmerait un slug).
        $query->where('author_kind', AuthorKind::Provider->value)->where('user_id', $provider['user_id'] ?? 0);
    }

    /**
     * @param  Builder<Article>  $query
     */
    private function page(Request $request, Builder $query): AnonymousResourceCollection
    {
        /** @var LengthAwarePaginator<int, Article> $articles */
        $articles = $query
            ->with(self::RELATIONS)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $authors = AuthorDirectory::for($articles->items(), Locale::fromRequest($request));

        // Chaque élément est déjà une Resource (avec les auteurs de toute la page) : la collection
        // les garde tels quels.
        return PublicArticleResource::collection(
            $articles->through(fn (Article $article): PublicArticleResource => PublicArticleResource::summary($article, $authors)),
        );
    }

    /**
     * @return Collection<int, Article>
     */
    private function related(Article $article): Collection
    {
        $categoryIds = $article->categories->pluck('id')->all();

        if ($categoryIds === []) {
            return collect();
        }

        return Article::query()
            ->published()
            ->whereKeyNot($article->id)
            ->where('language', $article->language)
            ->whereHas('categories', fn (Builder $categories) => $categories->whereIn('service_categories.id', $categoryIds))
            ->with(self::RELATIONS)
            ->orderByDesc('published_at')
            ->limit((int) config('blog.related_count'))
            ->get();
    }
}
