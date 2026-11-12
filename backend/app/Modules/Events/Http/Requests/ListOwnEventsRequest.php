<?php

namespace App\Modules\Events\Http\Requests;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /me/events : événements de l'organisateur, filtrables par statut.
 */
class ListOwnEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAnyOwn', Event::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(EventStatus::class)],
            ...Pagination::rules(),
        ];
    }
}
