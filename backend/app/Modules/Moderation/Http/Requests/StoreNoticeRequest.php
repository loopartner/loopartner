<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Models\User;
use App\Modules\Moderation\Support\Categories;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Support\Moderation\ModerationTargets;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /moderation/notices : signalement d'un contenu public (DSA art. 16 §2), avec ou sans
 * compte.
 *
 * - explication (a), emplacement exact par le type et l'identifiant du contenu, avec une
 *   précision facultative (b), nom et email (c : facultatifs pour une catégorie à identité
 *   facultative, pris du compte quand le signalant est connecté, interdits alors), déclaration
 *   de bonne foi (d) ;
 * - "homepage" est un champ piège invisible pour les humains : rempli, la requête est refusée.
 *
 * Tous les champs fixés par la plateforme (état, priorité, propriétaire…) sont ignorés : seul
 * validated() est utilisé.
 */
class StoreNoticeRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = (array) config('moderation.limits');
        $guest = ! $this->user() instanceof User;
        $identityRequired = $guest && ! Categories::identityOptional($this->string('category')->toString());
        $text = ['string', new NoHtml, new NoInvisibleCharacters];

        return [
            'target_type' => ['required', 'string', Rule::in(ModerationTargets::publicTypes())],
            'target_ref' => ['required', 'string', 'max:191', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'category' => ['required', 'string', Rule::in(Categories::keys())],
            'explanation' => ['required', ...$text, 'min:'.$limits['explanation']['min'], 'max:'.$limits['explanation']['max']],
            'location_detail' => ['nullable', ...$text, 'max:'.$limits['location_detail']],
            'name' => $guest
                ? [$identityRequired ? 'required' : 'nullable', ...$text, 'max:'.$limits['reporter_name']]
                : ['prohibited'],
            'email' => $guest
                ? [$identityRequired ? 'required' : 'nullable', 'string', 'email:rfc', 'max:191']
                : ['prohibited'],
            'good_faith' => ['required', 'accepted'],
            'homepage' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'good_faith.accepted' => 'Confirmez que les informations de votre signalement sont exactes et complètes, de bonne foi.',
            'name.prohibited' => 'Votre nom est celui de votre compte : ne le saisissez pas.',
            'email.prohibited' => 'Votre email est celui de votre compte : ne le saisissez pas.',
        ];
    }
}
