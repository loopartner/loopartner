<?php

namespace App\Modules\Directory\Http\Requests;

use App\Models\User;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Support\MatchingRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Validation\Validator;

/**
 * POST /me/conversations/{uuid}/messages/{messageUuid}/report : un participant signale un
 * message de son interlocuteur. Un message d'une autre conversation donne 404 ; signaler son
 * propre message donne 422.
 */
class ReportMessageRequest extends ConversationRequest
{
    private ?Message $message = null;

    protected function ability(): string
    {
        return 'report';
    }

    /**
     * Participant de la conversation (404 sinon), puis message de cette conversation (404 sinon),
     * avant toute validation.
     */
    public function authorize(): Response
    {
        $response = parent::authorize();

        if ($response->allowed()) {
            $this->reportedMessage();
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return MatchingRules::report();
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var User $user */
                $user = $this->user();

                if ($this->reportedMessage()->sender_id === $user->id) {
                    $validator->errors()->add('message', 'Vous ne pouvez pas signaler votre propre message.');
                }
            },
        ];
    }

    /**
     * Message visé, cherché uniquement dans cette conversation (404 sinon).
     */
    public function reportedMessage(): Message
    {
        if ($this->message === null) {
            $message = Message::query()
                ->where('conversation_id', $this->conversation()->id)
                ->where('uuid', (string) $this->route('messageUuid'))
                ->first();
            abort_if($message === null, 404, 'Message introuvable.');
            $this->message = $message;
        }

        return $this->message;
    }
}
