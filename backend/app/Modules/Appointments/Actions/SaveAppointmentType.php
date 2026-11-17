<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Appointments\Support\TypeRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Crée ou modifie un type de rendez-vous du prestataire connecté.
 *
 * - Types actifs : au plus appointments.active_types (403 premium_required en gratuit si le
 *   Premium en permet davantage, 409 sinon), comptés sous le verrou du calendrier.
 * - Types au total (actifs ou non) : au plus appointments.max_types (409).
 * - Une modification ne touche pas les rendez-vous déjà pris (ils ont leur copie du type).
 */
class SaveAppointmentType
{
    public function __construct(private Plan $plan) {}

    /**
     * @param  array<string, mixed>  $data  données validées
     */
    public function create(User $provider, array $data): AppointmentType
    {
        $calendar = Calendars::of($provider);

        return DB::transaction(function () use ($provider, $calendar, $data): AppointmentType {
            Calendars::lock($calendar->id);

            if (AppointmentType::query()->where('calendar_id', $calendar->id)->count() >= (int) config('appointments.max_types')) {
                throw new ConflictHttpException(sprintf('Vous avez déjà %d types de rendez-vous : supprimez-en un avant d\'en créer un autre.', (int) config('appointments.max_types')));
            }

            $type = new AppointmentType($data);
            $type->location_details = TypeRules::location($type->location_mode, $data['location_details'] ?? null);

            if ($type->is_active) {
                $this->assertActiveBelowLimit($provider, $calendar->id, null);
            }

            $type->forceFill(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id])->save();

            return $type;
        });
    }

    /**
     * @param  array<string, mixed>  $data  données validées (modification partielle)
     */
    public function update(User $provider, AppointmentType $type, array $data): AppointmentType
    {
        return DB::transaction(function () use ($provider, $type, $data): AppointmentType {
            Calendars::lock($type->calendar_id);
            $type = AppointmentType::query()->whereKey($type->id)->firstOrFail();
            $wasActive = $type->is_active;

            $type->fill($data);
            $type->location_details = TypeRules::location($type->location_mode, array_key_exists('location_details', $data) ? $data['location_details'] : $type->location_details);

            if ($type->is_active && ! $wasActive) {
                $this->assertActiveBelowLimit($provider, $type->calendar_id, $type->id);
            }

            $type->save();

            return $type;
        });
    }

    private function assertActiveBelowLimit(User $provider, int $calendarId, ?int $exceptId): void
    {
        $active = AppointmentType::query()
            ->where('calendar_id', $calendarId)
            ->where('is_active', true)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->count();

        $this->plan->assertBelow($provider, 'active_types', $active, 'appointment_types', 'Vous avez déjà %d types de rendez-vous actifs : désactivez-en un avant d\'en activer un autre.');
    }
}
