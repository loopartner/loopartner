<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\TimeOfDay;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /me/appointments/availability/rules : toutes les plages hebdomadaires
 * {"rules": [{"weekday": 1, "start": "09:00", "end": "12:00"}]}, jour ISO (1 = lundi), heures
 * locales par pas de 5 minutes, "24:00" accepté pour une fin. Liste vide : aucune plage.
 */
class ReplaceAvailabilityRulesRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('manage', Calendar::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'rules' => ['present', 'array', 'max:'.(int) config('appointments.plans.premium.weekly_ranges')],
            'rules.*' => ['required', 'array:weekday,start,end'],
            'rules.*.weekday' => ['required', 'integer', 'between:1,7'],
            'rules.*.start' => ['required', 'string', 'regex:'.TimeOfDay::PATTERN, 'not_in:24:00'],
            'rules.*.end' => ['required', 'string', 'regex:'.TimeOfDay::PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rules.*.start.regex' => 'Heure au format HH:MM, par pas de 5 minutes.',
            'rules.*.end.regex' => 'Heure au format HH:MM, par pas de 5 minutes.',
        ];
    }
}
