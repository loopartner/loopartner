<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Models\User;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Support\OfferRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /me/offers/{uuid} : modification partielle, à tout statut. Une offre publiée est
 * modifiée directement (pas de validation préalable, décision 39). L'autorisation passe avant
 * la validation : l'offre d'un autre utilisateur donne 404 quelles que soient les données.
 */
class UpdateOfferRequest extends FormRequest
{
    private ?Offer $offer = null;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->offer());
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

        return OfferRules::rules(true, $this->all(), $user, $this->offer()->pricing_mode, $this->offer()->image_media_id);
    }

    public function offer(): Offer
    {
        if ($this->offer === null) {
            $offer = Offer::query()->where('uuid', (string) $this->route('uuid'))->first();
            abort_if($offer === null, 404, 'Offre introuvable.');
            $this->offer = $offer;
        }

        return $this->offer;
    }
}
