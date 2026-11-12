<?php

namespace App\Modules\Events\Http\Requests;

use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /me/event-registrations : inscriptions de l'utilisateur, à venir (par défaut) ou passées.
 */
class ListMyRegistrationsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'when' => ['nullable', 'in:upcoming,past'],
            ...Pagination::rules(),
        ];
    }
}
