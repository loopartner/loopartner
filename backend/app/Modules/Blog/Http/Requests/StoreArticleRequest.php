<?php

namespace App\Modules\Blog\Http\Requests;

use App\Models\User;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Models\Article;
use App\Modules\Blog\Support\ArticleRules;
use App\Modules\Blog\Support\Authors;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/articles : création d'un article, toujours en brouillon (titre et langue
 * obligatoires, le reste peut venir plus tard). Texte brut, corps en document de blocs.
 */
class StoreArticleRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Article::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return ArticleRules::rules(partial: false, kind: Authors::kindOf($user) ?? AuthorKind::Entrepreneur);
    }
}
