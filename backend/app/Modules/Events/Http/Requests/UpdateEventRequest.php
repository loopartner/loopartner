<?php

namespace App\Modules\Events\Http\Requests;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PATCH /me/events/{uuid} : modification partielle. Un champ absent reste inchangé.
 *
 * L'autorisation passe avant la validation : l'événement d'un autre utilisateur donne 404,
 * quelles que soient les données envoyées.
 */
class UpdateEventRequest extends FormRequest
{
    private ?Event $event = null;

    public function authorize(): Response
    {
        return Gate::inspect('update', $this->event());
    }

    protected function prepareForValidation(): void
    {
        $this->replace(EventRules::normalize($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->user();

        return EventRules::rules(partial: true, organizer: $user);
    }

    /**
     * Événement visé par l'URL (404 s'il n'existe pas).
     */
    public function event(): Event
    {
        if ($this->event === null) {
            $event = Event::query()->where('uuid', (string) $this->route('uuid'))->first();
            abort_if($event === null, 404, 'Événement introuvable.');
            $this->event = $event;
        }

        return $this->event;
    }
}
