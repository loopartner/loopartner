<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleContent;
use App\Modules\Blog\Support\ArticleImages;
use App\Modules\Blog\Support\ArticleQuota;
use App\Modules\Blog\Support\Authors;
use App\Support\Media\MediaLibrary;
use App\Support\Media\MediaPurpose;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Création d'un article, toujours en brouillon. Le type d'auteur (rédaction, prestataire,
 * entrepreneur) est fixé ici d'après le rôle et ne change plus. Plafond de brouillons (409).
 */
class CreateArticle
{
    public function __construct(private MediaLibrary $library) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     *
     * @throws ConflictHttpException
     */
    public function handle(User $author, array $data): Article
    {
        $kind = Authors::kindOf($author) ?? throw new LogicException('Cet utilisateur ne peut pas écrire d\'article.');

        return DB::transaction(function () use ($author, $kind, $data): Article {
            ArticleQuota::lock($author->id);
            ArticleQuota::assertCanCreateDraft($author->id);

            $content = ArticleContent::merge(ArticleContent::blank(), ArticleContent::fromInput($data));

            $article = new Article;
            $article->forceFill([
                'uuid' => (string) Str::uuid(),
                'user_id' => $author->id,
                'author_kind' => $kind,
                'status' => ArticleStatus::Draft,
                'version' => 1,
            ]);
            ArticleContent::apply($article, $content);
            $article->save();

            $article->categories()->sync(ArticleContent::categoryIds($content));

            if (isset($data['cover']) && is_string($data['cover'])) {
                $article->forceFill([
                    'cover_media_id' => $this->library->replace($author, MediaPurpose::ArticleCover, $data['cover'], null, 'cover'),
                ])->save();
            }

            /** @var array<string, mixed> $body */
            $body = $content['body'];
            ArticleImages::syncArticle($author, $article, $body);

            return $article;
        });
    }
}
