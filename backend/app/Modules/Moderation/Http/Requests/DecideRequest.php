<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Modules\Moderation\Enums\DecisionGround;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Support\Categories;
use App\Modules\Moderation\Support\TermsClauses;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/moderation/targets/{type}/{ref}/decisions : exposé des motifs d'une décision
 * (DSA art. 17 §3).
 *
 * - Restriction : fondement (contenu illicite avec sa référence juridique, ou CGU avec la
 *   clause), catégorie, faits ; ce que l'auteur peut corriger, si c'est possible.
 * - Aucune mesure : faits seulement ; « manifestement infondé » facultatif.
 * - version : empreinte du contenu lu par l'admin (obligatoire pour une restriction).
 */
class DecideRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = (array) config('moderation.limits');
        $restricts = $this->input('measure') === DecisionMeasure::RestrictVisibility->value;
        $ground = $this->input('ground');
        $text = ['string', new NoHtml, new NoInvisibleCharacters];

        return [
            'measure' => ['required', 'string', Rule::enum(DecisionMeasure::class)],
            'ground' => $restricts ? ['required', 'string', Rule::enum(DecisionGround::class)] : ['prohibited'],
            'category' => $restricts ? ['required', 'string', Rule::in(Categories::keys())] : ['prohibited'],
            'legal_reference' => $restricts && $ground === DecisionGround::Illegal->value
                ? ['required', ...$text, 'min:5', 'max:'.$limits['legal_reference']]
                : ['prohibited'],
            'terms_clause' => $restricts && $ground === DecisionGround::Terms->value
                ? ['required', 'string', Rule::in(TermsClauses::keys())]
                : ['prohibited'],
            'facts' => ['required', ...$text, 'min:'.$limits['facts']['min'], 'max:'.$limits['facts']['max']],
            'correction_hint' => $restricts ? ['nullable', ...$text, 'min:10', 'max:'.$limits['correction_hint']] : ['prohibited'],
            'version' => [$restricts ? 'required' : 'nullable', 'string', 'regex:/\A[0-9a-f]{64}\z/'],
            'manifestly_unfounded' => $restricts ? ['prohibited'] : ['sometimes', 'boolean'],
        ];
    }
}
