<?php

namespace Database\Factories\Modules\Events;

use App\Models\User;
use App\Modules\Events\Enums\AddressVisibility;
use App\Modules\Events\Enums\EventFormat;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\EventType;
use App\Modules\Events\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Événement en brouillon, en présentiel, gratuit, dans 10 jours à Lyon.
 *
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(10)->setTime(16, 0)->startOfMinute();

        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'status' => EventStatus::Draft,
            'version' => 1,
            'type' => EventType::Workshop,
            'format' => EventFormat::InPerson,
            'title' => 'Atelier : bien démarrer sa comptabilité',
            'description' => 'Un atelier pratique de deux heures pour mettre en place la comptabilité de sa jeune entreprise.',
            'organizer_name' => 'Collectif des indépendants',
            'language' => 'fr',
            'venue_name' => 'La Cordée',
            'address_line' => '12 rue de la République',
            'postal_code' => '69001',
            'city' => 'Lyon',
            'country' => 'FR',
            'address_visibility' => AddressVisibility::Public,
            'online_url' => null,
            'external_url' => null,
            'is_free' => true,
            'ticket_url' => null,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'timezone' => 'Europe/Paris',
            'capacity' => null,
        ];
    }

    /**
     * Publié (slug et date de publication), toujours à venir.
     */
    public function published(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => EventStatus::Published,
            'slug' => Str::slug((string) $attributes['title']).'-'.Str::lower(Str::random(6)),
            'submitted_at' => now()->subDays(2),
            'published_at' => now()->subDay(),
        ]);
    }

    public function pendingReview(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::PendingReview,
            'submitted_at' => now()->subHour(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => EventStatus::Rejected,
            'submitted_at' => now()->subDays(2),
            'rejected_at' => now()->subDay(),
            'rejection_reason' => 'La description ne présente pas le programme de l\'atelier.',
        ]);
    }

    /**
     * En ligne, avec un lien de connexion.
     */
    public function online(): static
    {
        return $this->state(fn (): array => [
            'format' => EventFormat::Online,
            'type' => EventType::Webinar,
            'venue_name' => null,
            'address_line' => null,
            'postal_code' => null,
            'city' => null,
            'country' => null,
            'online_url' => 'https://visio.exemple.fr/salle/atelier-compta',
        ]);
    }

    /**
     * Payant : inscription sur une billetterie externe.
     */
    public function paid(): static
    {
        return $this->state(fn (): array => [
            'is_free' => false,
            'ticket_url' => 'https://billetterie.exemple.fr/salon',
            'capacity' => null,
        ]);
    }

    /**
     * Commencé il y a une heure, terminé dans une heure.
     */
    public function ongoing(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subHour()->startOfMinute(),
            'ends_at' => now()->addHour()->startOfMinute(),
        ]);
    }

    /**
     * Terminé hier (statut inchangé : à combiner avec published() ou past()).
     */
    public function ended(): static
    {
        return $this->state(fn (): array => [
            'starts_at' => now()->subDay()->subHours(2)->startOfMinute(),
            'ends_at' => now()->subDay()->startOfMinute(),
        ]);
    }

    public function past(): static
    {
        return $this->published()->ended()->state(fn (): array => ['status' => EventStatus::Past]);
    }
}
