<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Modules\Moderation\Support\TrackingToken;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Recours contre une décision : le texte (30 à 5000 caractères, texte brut) et, pour un
 * signalant sans compte, son jeton de suivi (route /moderation/notices/{uuid}/appeal).
 */
class AppealRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = (array) config('moderation.limits.appeal');

        return [
            'statement' => ['required', 'string', 'min:'.$limits['min'], 'max:'.$limits['max'], new NoHtml, new NoInvisibleCharacters],
            'token' => $this->routeIs('moderation.notices.appeal')
                ? ['required', 'string', 'size:'.TrackingToken::LENGTH]
                : ['prohibited'],
        ];
    }
}
