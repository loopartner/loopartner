<?php

namespace App\Modules\Blog\Http\Requests;

use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Models\Article;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * GET /me/articles : ses propres articles, filtre facultatif par statut.
 */
class ListOwnArticlesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::allows('viewAnyOwn', Article::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Pagination::rules(),
            'status' => ['sometimes', 'nullable', Rule::enum(ArticleStatus::class)],
        ];
    }
}
