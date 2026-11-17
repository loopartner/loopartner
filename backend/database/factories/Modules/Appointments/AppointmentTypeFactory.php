<?php

namespace Database\Factories\Modules\Appointments;

use App\Modules\Appointments\Enums\LocationMode;
use App\Modules\Appointments\Enums\TypeVisibility;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\Calendar;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Type de rendez-vous de test : premier échange de 30 minutes en visio (lien fictif),
 * réservable dans une mise en relation, à confirmer par le prestataire.
 *
 * @extends Factory<AppointmentType>
 */
class AppointmentTypeFactory extends Factory
{
    protected $model = AppointmentType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'calendar_id' => Calendar::factory(),
            'title' => 'Premier échange',
            'description' => 'Un premier échange pour comprendre votre projet.',
            'duration_minutes' => 30,
            'location_mode' => LocationMode::Video,
            'location_details' => 'https://visio.exemple.fr/salle-test',
            'visibility' => TypeVisibility::Relation,
            'auto_confirm' => false,
            'is_active' => true,
            'position' => 0,
        ];
    }

    public function public(): static
    {
        return $this->state(['visibility' => TypeVisibility::Public]);
    }

    public function autoConfirm(): static
    {
        return $this->state(['auto_confirm' => true]);
    }
}
