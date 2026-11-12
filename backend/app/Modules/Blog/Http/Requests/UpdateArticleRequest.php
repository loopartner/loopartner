<?php

namespace App\Modules\Blog\Http\Requests;

use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /me/articles/{uuid} : modification partielle. Un champ absent reste inchangé.
 *
 * L'autorisation passe avant la validation : l'article d'un autre utilisateur donne 404, quelles
 * que soient les données envoyées. Les règles suivent le type d'auteur de l'article.
 */
class UpdateArticleRequest extends FormRequest
{
    private ?Article $article = null;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->article());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ArticleRules::rules(partial: true, kind: $this->article()->author_kind);
    }

    /**
     * Article visé par l'URL (404 s'il n'existe pas).
     */
    public function article(): Article
    {
        if ($this->article === null) {
            $article = Article::query()->where('uuid', (string) $this->route('uuid'))->first();
            abort_if($article === null, 404, 'Article introuvable.');
            $this->article = $article;
        }

        return $this->article;
    }
}
