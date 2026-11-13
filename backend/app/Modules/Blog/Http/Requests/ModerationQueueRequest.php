<?php

namespace App\Modules\Blog\Http\Requests;

use App\Modules\Blog\Support\ModerationQueue;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /admin/blog/queue : file de modération, filtre facultatif par type (article, revision).
 */
class ModerationQueueRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...Pagination::rules(),
            'kind' => ['sometimes', 'nullable', Rule::in(ModerationQueue::KINDS)],
        ];
    }
}
