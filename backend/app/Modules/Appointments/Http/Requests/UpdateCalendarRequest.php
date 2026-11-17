<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Models\Calendar;
use App\Support\Time\LocalTime;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PUT /me/appointments/calendar : réglages complets du calendrier (prestataire). Bornes dans
 * config/appointments.php ; limites du plan vérifiées par SaveCalendar.
 */
class UpdateCalendarRequest extends FormRequest
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
        $offsets = array_values(array_unique(array_merge(
            (array) config('appointments.plans.free.reminder_offsets'),
            (array) config('appointments.plans.premium.reminder_offsets'),
        )));

        return [
            'timezone' => ['required', 'string', 'max:64', function (string $attribute, mixed $value, Closure $fail): void {
                if (! LocalTime::isValidTimezone($value)) {
                    $fail('Choisissez un fuseau horaire valide (par exemple Europe/Paris).');
                }
            }],
            'is_enabled' => ['required', 'boolean'],
            'public_page_enabled' => ['sometimes', 'boolean'],
            'slot_step_minutes' => ['required', 'integer', Rule::in((array) config('appointments.slot_steps'))],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:'.(int) config('appointments.max_buffer_minutes'), 'multiple_of:5'],
            'min_notice_hours' => ['required', 'integer', 'min:0', 'max:'.(int) config('appointments.max_min_notice_hours')],
            'horizon_days' => ['required', 'integer', 'min:1', 'max:'.(int) config('appointments.plans.premium.horizon_days')],
            'reminder_offsets' => ['required', 'array', 'min:1', 'max:'.(int) config('appointments.plans.premium.max_reminder_offsets')],
            'reminder_offsets.*' => ['required', 'integer', 'distinct', Rule::in($offsets)],
            'user_id' => ['prohibited'],
        ];
    }
}
