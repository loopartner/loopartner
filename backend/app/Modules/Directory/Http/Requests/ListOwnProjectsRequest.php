<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /me/projects : filtre facultatif sur le statut, et pagination.
 */
class ListOwnProjectsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAnyOwn', Project::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(ProjectStatus::class)],
            ...Pagination::rules(),
        ];
    }
}
