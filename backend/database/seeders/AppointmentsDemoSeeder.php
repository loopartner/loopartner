<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Appointments\Actions\BookAppointment;
use App\Modules\Appointments\Actions\ConfirmAppointment;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\LocationMode;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Enums\TypeVisibility;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Données de démonstration du module Appointments, appelées par DatabaseSeeder UNIQUEMENT si
 * DemoDataGuard l'autorise (poste local). Aucun email.
 *
 * - prestataire@example.com (Premium) : calendrier ouvert, page de réservation publique,
 *   plages du lundi au vendredi (09:00-12:00 et 14:00-18:00, Europe/Paris), deux types (un
 *   premier échange public en visio, avec un lien fictif ; une visite chez le client réservée
 *   aux relations).
 * - Avec particulier@example.com, dans leur mise en relation acceptée : un rendez-vous
 *   confirmé le mardi suivant à 10:00, et une proposition du prestataire le jeudi suivant à
 *   15:00, en attente de réponse.
 *
 * Idempotent : rien n'est recréé si le calendrier du prestataire existe déjà.
 */
class AppointmentsDemoSeeder extends Seeder
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public const DEMO_TYPES = [
        'Premier échange (visio)' => [
            'description' => '[Démo] Trente minutes pour comprendre votre projet et vos délais.',
            'duration_minutes' => 30, 'location_mode' => LocationMode::Video, 'location_details' => 'https://visio.example.com/demo-loopartner',
            'visibility' => TypeVisibility::Public, 'auto_confirm' => false, 'position' => 0,
        ],
        'Visite sur place' => [
            'description' => '[Démo] Une heure chez vous pour voir les lieux.',
            'duration_minutes' => 60, 'location_mode' => LocationMode::ClientPlace, 'location_details' => null,
            'visibility' => TypeVisibility::Relation, 'auto_confirm' => false, 'position' => 1,
        ],
    ];

    public const DEMO_NOTE = '[Démo] Je souhaite parler du calendrier de mon projet.';

    public function run(): void
    {
        if (! DemoDataGuard::allowsSeeding()) {
            throw new RuntimeException('Les données de démonstration du module Appointments sont réservées au poste local (DemoDataGuard).');
        }

        Notification::fake();

        $provider = User::query()->where('email', 'prestataire@example.com')->first();
        $particulier = User::query()->where('email', 'particulier@example.com')->first();

        if (! $provider instanceof User || Calendar::query()->where('user_id', $provider->id)->exists()) {
            return;
        }

        $calendar = $this->seedCalendar($provider);

        if ($particulier instanceof User) {
            $this->seedAppointments($provider, $particulier, $calendar);
        }
    }

    private function seedCalendar(User $provider): Calendar
    {
        $calendar = new Calendar([
            'timezone' => 'Europe/Paris',
            'is_enabled' => true,
            'public_page_enabled' => true,
            'slot_step_minutes' => 30,
            'buffer_minutes' => 15,
            'min_notice_minutes' => 12 * 60,
            'horizon_days' => 60,
            'reminder_offsets' => [1440, 120],
        ]);
        $calendar->forceFill(['user_id' => $provider->id])->save();

        foreach (self::DEMO_TYPES as $title => $data) {
            $type = new AppointmentType(['title' => $title, ...$data]);
            $type->forceFill(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id])->save();
        }

        foreach (range(1, 5) as $weekday) {
            foreach ([[9 * 60, 12 * 60], [14 * 60, 18 * 60]] as [$start, $end]) {
                (new AvailabilityRule)->forceFill(['calendar_id' => $calendar->id, 'weekday' => $weekday, 'start_minute' => $start, 'end_minute' => $end])->save();
            }
        }

        return $calendar;
    }

    private function seedAppointments(User $provider, User $particulier, Calendar $calendar): void
    {
        $matchRequest = MatchRequest::query()
            ->where('type', MatchRequestType::Proposal->value)
            ->where('author_id', $provider->id)
            ->where('recipient_id', $particulier->id)
            ->where('status', MatchRequestStatus::Accepted->value)
            ->first();

        if ($matchRequest === null) {
            return;
        }

        $nextWeek = CarbonImmutable::now('Europe/Paris')->next('monday');
        $types = AppointmentType::query()->where('calendar_id', $calendar->id)->get()->keyBy('title');
        $book = app(BookAppointment::class);

        $confirmed = $book->place($particulier, Party::Client, $calendar, [
            'type' => (string) $types['Premier échange (visio)']->uuid,
            'starts_at' => LocalTime::instant($nextWeek->addDay()->toDateString(), 10 * 60, 'Europe/Paris')->toIso8601ZuluString(),
            'note' => self::DEMO_NOTE,
        ], $particulier->id, AppointmentOrigin::Relation, fn (): int => $matchRequest->id);
        app(ConfirmAppointment::class)->handle($provider, $confirmed);

        $book->place($provider, Party::Provider, $calendar, [
            'type' => (string) $types['Visite sur place']->uuid,
            'starts_at' => LocalTime::instant($nextWeek->addDays(3)->toDateString(), 15 * 60, 'Europe/Paris')->toIso8601ZuluString(),
        ], $particulier->id, AppointmentOrigin::Relation, fn (): int => $matchRequest->id);
    }
}
