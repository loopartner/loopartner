<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /admin/moderation/appeals/{uuid}/decide : accepté ou rejeté, avec un motif envoyé à
 * l'auteur du recours (10 à 1000 caractères, texte brut).
 */
class DecideAppealRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $limits = (array) config('moderation.limits.reason');

        return [
            'upheld' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:'.$limits['min'], 'max:'.$limits['max'], new NoHtml, new NoInvisibleCharacters],
        ];
    }
}
