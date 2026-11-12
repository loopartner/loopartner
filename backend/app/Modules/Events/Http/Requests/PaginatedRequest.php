<?php

namespace App\Modules\Events\Http\Requests;

use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Liste paginée sans autre filtre (inscrits, annonces). L'autorisation est vérifiée par le
 * contrôleur, sur l'événement visé.
 */
class PaginatedRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return Pagination::rules();
    }
}
