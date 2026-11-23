<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Models\User;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Support\OfferRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/offers : nouvelle offre, toujours en brouillon.
 */
class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', Offer::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->replace(OfferRules::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return OfferRules::rules(false, $this->all(), $user);
    }
}
