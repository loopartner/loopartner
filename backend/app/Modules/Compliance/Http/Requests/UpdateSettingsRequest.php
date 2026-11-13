<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Enums\ReminderFrequency;
use App\Modules\Compliance\Models\Company;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PATCH /me/compliance/settings.
 *
 * - emails_enabled : rappels et alertes par email.
 * - reminder_frequency : daily (récapitulatif les jours où une échéance arrive à un délai de
 *   rappel) ou weekly (le lundi, échéances des 14 jours suivants).
 * - reminder_offsets : 1 à compliance.reminders.premium_max_offsets délais, en jours, parmi
 *   compliance.reminders.premium_offsets (sans premium : un seul, parmi free_offsets).
 * - threshold_levels : 1 à premium_max_levels paliers en %, de premium_min à 100, par pas de
 *   step (sans premium : les paliers par défaut).
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('use', Company::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'emails_enabled' => ['sometimes', 'boolean'],
            'reminder_frequency' => ['sometimes', 'string', Rule::enum(ReminderFrequency::class)],
            'reminder_offsets' => ['sometimes', 'array', 'min:1', 'max:'.(int) config('compliance.reminders.premium_max_offsets')],
            'reminder_offsets.*' => ['integer:strict', 'distinct', Rule::in(array_map('intval', (array) config('compliance.reminders.premium_offsets')))],
            'threshold_levels' => ['sometimes', 'array', 'min:1', 'max:'.(int) config('compliance.thresholds.premium_max_levels')],
            'threshold_levels.*' => ['integer:strict', 'distinct', 'between:'.(int) config('compliance.thresholds.premium_min').',100', self::step()],
            'active_company_id' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }

    private static function step(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_int($value) && $value % (int) config('compliance.thresholds.step') !== 0) {
                $fail('Un palier d\'alerte est un multiple de '.(int) config('compliance.thresholds.step').' %.');
            }
        };
    }
}
