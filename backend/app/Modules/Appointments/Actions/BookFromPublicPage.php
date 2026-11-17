<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Accounts\Actions\FindPublishedProvider;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Exceptions\RelationExists;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Support\Plan;
use App\Modules\Directory\Actions\ManageAppointmentContact;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Réservation sur la page publique d'un prestataire Premium (décision 52).
 *
 * - Particulier ou entrepreneur avec un email vérifié (403 sinon) ; page fermée, profil non
 *   publié ou masqué, prestataire sans Premium : 404.
 * - Déjà en relation acceptée avec ce prestataire : 409 relation_exists (réserver depuis la
 *   relation) ; demande déjà en attente, blocage, plafond de demandes : règles d'un contact
 *   (Action publique Directory\Actions\ManageAppointmentContact, 409 ou 403).
 * - Le motif (20 à 500 caractères) devient le message de la demande de contact, créée dans la
 *   même transaction que le rendez-vous ; le rendez-vous attend toujours la confirmation du
 *   prestataire, qui révèle le nom du client.
 *
 * @phpstan-import-type BookingInput from BookAppointment
 */
class BookFromPublicPage
{
    public function __construct(
        private FindPublishedProvider $findPublishedProvider,
        private ManageAppointmentContact $contact,
        private BookAppointment $bookAppointment,
        private Plan $plan,
    ) {}

    /**
     * @param  BookingInput  $data
     */
    public function handle(User $client, string $providerSlug, array $data): Appointment
    {
        $calendar = $this->openPage($providerSlug);

        if (! $client->hasAnyRole([AccountType::Particulier->value, AccountType::Entrepreneur->value])) {
            throw new AccessDeniedHttpException('Seuls les particuliers et les entrepreneurs prennent rendez-vous avec un prestataire.');
        }

        $existing = $this->contact->acceptedBetween($client->id, $calendar->user_id);

        if ($existing !== null) {
            throw new RelationExists($existing);
        }

        return $this->bookAppointment->place(
            $client,
            Party::Client,
            $calendar,
            $data,
            $client->id,
            AppointmentOrigin::PublicPage,
            fn (): int => $this->contact->submit($client, $calendar->user_id, (string) ($data['note'] ?? ''))['match_request_id'],
        );
    }

    /**
     * Calendrier dont la page de réservation publique est ouverte (404 sinon).
     */
    public function openPage(string $providerSlug): Calendar
    {
        $provider = $this->findPublishedProvider->handle($providerSlug);
        $calendar = $provider === null ? null : Calendar::query()->where('user_id', $provider['user_id'])->first();

        if ($calendar === null || ! $calendar->is_enabled || ! $this->plan->publicPageOpen($calendar)) {
            throw new NotFoundHttpException('Page de réservation introuvable.');
        }

        return $calendar;
    }
}
