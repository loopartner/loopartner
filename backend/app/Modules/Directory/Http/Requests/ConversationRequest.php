<?php

namespace App\Modules\Directory\Http\Requests;

use App\Modules\Directory\Models\Conversation;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Requête visant une conversation (/me/conversations/{uuid}/...). L'autorisation passe avant la
 * validation : une conversation inconnue ou dont l'utilisateur n'est pas participant donne 404,
 * quelles que soient les données envoyées.
 */
abstract class ConversationRequest extends FormRequest
{
    private ?Conversation $conversation = null;

    /**
     * Capacité de ConversationPolicy vérifiée par authorize().
     */
    abstract protected function ability(): string;

    public function authorize(): Response
    {
        return Gate::inspect($this->ability(), $this->conversation());
    }

    public function conversation(): Conversation
    {
        if ($this->conversation === null) {
            $conversation = Conversation::query()
                ->with('participants.user')
                ->where('uuid', (string) $this->route('uuid'))
                ->first();
            abort_if($conversation === null, 404, 'Conversation introuvable.');
            $this->conversation = $conversation;
        }

        return $this->conversation;
    }
}
