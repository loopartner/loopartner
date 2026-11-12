<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Support\ModerationQueue;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /admin/events/queue : file de modération, filtrable par type d'élément.
 */
class ModerationQueueRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['nullable', Rule::in(ModerationQueue::KINDS)],
            ...Pagination::rules(),
        ];
    }
}
