<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Models\Calendar;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * GET /me/appointments/slots : aperçu des créneaux du prestataire connecté pour un de ses
 * types (uuid), journées locales du calendrier "AAAA-MM-JJ", 31 jours au plus ; par défaut les
 * 7 prochains jours. Prestataires seulement (403), vérifié avant la validation.
 */
class SlotRangeRequest extends FormRequest
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
        $maxDays = (int) config('appointments.max_slot_range_days');

        return [
            'type' => ['required', 'string', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from', function (string $attribute, mixed $value, \Closure $fail) use ($maxDays): void {
                $from = $this->input('from');

                if (is_string($from) && is_string($value) && strtotime($value) - strtotime($from) > ($maxDays - 1) * 86400) {
                    $fail("Demandez au plus {$maxDays} jours de créneaux à la fois.");
                }
            }],
        ];
    }
}
