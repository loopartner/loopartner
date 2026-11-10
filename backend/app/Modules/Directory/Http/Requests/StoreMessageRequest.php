<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Support\MatchingRules;

/**
 * POST /me/conversations/{uuid}/messages : texte brut, 5000 caractères maximum, HTML et
 * caractères invisibles refusés. Blocage ou compte de l'interlocuteur supprimé : 403
 * (ConversationPolicy::sendMessage), avant la validation.
 */
class StoreMessageRequest extends ConversationRequest
{
    protected function ability(): string
    {
        return 'sendMessage';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'body' => MatchingRules::messageBody(),
        ];
    }
}
