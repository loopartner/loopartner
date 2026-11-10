<?php

namespace App\Modules\Directory\Http\Requests;

use App\Support\Pagination;
use Illuminate\Validation\Rule;

/**
 * GET /me/conversations/{uuid}/messages.
 *
 * - Sans "after" : pagination standard, du plus récent au plus ancien.
 * - Avec "after" (uuid d'un message de cette conversation) : seulement les messages plus
 *   récents, du plus ancien au plus récent, pour l'interrogation régulière du frontend.
 *   Un uuid d'une autre conversation donne 422, comme un uuid inconnu.
 */
class ListMessagesRequest extends ConversationRequest
{
    protected function ability(): string
    {
        return 'view';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'after' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('messages', 'uuid')->where('conversation_id', $this->conversation()->id),
            ],
            ...Pagination::rules(),
        ];
    }
}
