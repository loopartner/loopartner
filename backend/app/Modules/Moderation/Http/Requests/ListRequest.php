<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Listes paginées du module Moderation (page, per_page : 50 au plus, 422 au-delà).
 */
class ListRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Pagination::rules();
    }
}
