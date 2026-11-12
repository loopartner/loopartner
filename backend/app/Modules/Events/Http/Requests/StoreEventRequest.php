<?php

namespace App\Modules\Events\Http\Requests;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\EventRules;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * POST /me/events : création d'un événement, toujours en brouillon.
 * Texte brut (HTML et caractères invisibles refusés), liens en https vers des domaines publics.
 */
class StoreEventRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Event::class);
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

        return EventRules::rules(partial: false, organizer: $user);
    }
}
