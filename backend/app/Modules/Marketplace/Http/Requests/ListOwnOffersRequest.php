<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Enums\OfferStatus;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Pagination;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /me/offers : offres du prestataire connecté, filtre facultatif par statut.
 */
class ListOwnOffersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Offer::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::enum(OfferStatus::class)],
            ...Pagination::rules(),
        ];
    }
}
