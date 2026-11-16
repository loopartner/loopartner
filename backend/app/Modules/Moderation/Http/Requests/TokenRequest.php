<?php

namespace App\Modules\Moderation\Http\Requests;

use App\Modules\Moderation\Support\TrackingToken;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /moderation/notices/{uuid}/lookup : le signalant sans compte envoie son jeton de suivi
 * dans le corps de la requête (jamais dans l'URL, pour qu'il n'apparaisse dans aucun journal).
 */
class TokenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:'.TrackingToken::LENGTH],
        ];
    }
}
