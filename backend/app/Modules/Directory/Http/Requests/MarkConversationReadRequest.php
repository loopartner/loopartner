<?php

namespace App\Modules\Directory\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * POST /me/conversations/{uuid}/read : "up_to" (facultatif) est l'uuid d'un message de cette
 * conversation ; par défaut, tous les messages sont marqués comme lus.
 */
class MarkConversationReadRequest extends ConversationRequest
{
    protected function ability(): string
    {
        return 'markRead';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'up_to' => [
                'nullable',
                'string',
                'uuid',
                Rule::exists('messages', 'uuid')->where('conversation_id', $this->conversation()->id),
            ],
        ];
    }
}
