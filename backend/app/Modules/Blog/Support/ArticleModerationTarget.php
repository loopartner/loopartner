<?php

namespace App\Modules\Blog\Support;

use App\Models\User;
use App\Modules\Blog\Models\Article;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Article publié signalable (module Moderation), désigné par son slug public (la modération
 * accepte aussi l'uuid). Masqué : absent des listes, de la page (404), des articles liés et du
 * plan du site, sans changer son statut. La validation avant publication et la dépublication
 * restent celles du module Blog, inchangées.
 */
class ArticleModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        $article = Article::query()->published()->with('cover')->where('slug', $ref)->first();

        return $article === null ? null : self::content($article);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $article = self::query($ref)->with('cover')->first();

        return $article === null ? null : self::content($article);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $article = self::query($ref)->lockForUpdate()->first();

            if ($article !== null) {
                Restriction::apply($article, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $article = self::query($ref)->lockForUpdate()->first();

            if ($article !== null) {
                Restriction::lift($article, $decisionUuid);
            }
        });
    }

    /**
     * @return Builder<Article>
     */
    private static function query(string $ref): Builder
    {
        return Article::query()->where(fn (Builder $query) => $query->where('slug', $ref)->orWhere('uuid', $ref));
    }

    private static function content(Article $article): TargetContent
    {
        $cover = $article->cover?->url();

        return new TargetContent(
            type: 'article',
            ref: $article->slug ?? $article->uuid,
            ownerId: $article->user_id,
            label: $article->title,
            text: TargetContent::joinText([
                'Titre' => $article->title,
                'Chapô' => $article->excerpt,
                'Auteur' => $article->author_name,
                'Corps' => ArticleBody::plainText($article->body),
            ]),
            version: TargetContent::fingerprint(['version' => $article->version]),
            restrictedBy: Restriction::decisionOf($article),
            images: $cover !== null ? [$cover] : [],
            publicPath: $article->slug !== null ? '/articles/'.$article->slug : null,
        );
    }
}
