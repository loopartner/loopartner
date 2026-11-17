<?php

namespace App\Modules\Appointments\Actions;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentNotice;
use App\Modules\Appointments\Enums\AppointmentOrigin;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Enums\HoldKind;
use App\Modules\Appointments\Enums\NoteKind;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Enums\TypeVisibility;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Appointments\Notifications\AppointmentNotification;
use App\Modules\Appointments\Support\AppointmentNotes;
use App\Modules\Appointments\Support\BookableTypes;
use App\Modules\Appointments\Support\BookingLimits;
use App\Modules\Appointments\Support\Calendars;
use App\Modules\Appointments\Support\Relation;
use App\Modules\Appointments\Support\SlotLedger;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Prend un rendez-vous (décision 52).
 *
 * - inRelation() : dans une demande de mise en relation acceptée ; le client réserve (confirmé
 *   tout de suite si le type est en confirmation automatique, sinon « requested »), le
 *   prestataire propose (« proposed », à confirmer par le client).
 * - place() : le cœur commun, aussi utilisé par la page publique (BookFromPublicPage).
 *
 * Dans une seule transaction : verrou du calendrier du prestataire, puis de la ligne users du
 * client ; type réservable ; limites ; créneau libre (même calcul que l'affichage) ; création
 * de la demande de contact pour la page publique ; insertion du rendez-vous et du créneau
 * retenu (index unique). Les emails partent après la validation de la transaction.
 *
 * @phpstan-type BookingInput array{type: string, starts_at: string, note?: string|null, address?: string|null, phone?: string|null}
 */
class BookAppointment
{
    public function __construct(
        private Relation $relation,
        private BookableTypes $bookableTypes,
        private BookingLimits $limits,
        private SlotLedger $ledger,
    ) {}

    /**
     * @param  BookingInput  $data
     */
    public function inRelation(User $actor, string $matchRequestUuid, array $data): Appointment
    {
        ['context' => $context, 'party' => $party] = $this->relation->forBooking($actor, $matchRequestUuid);
        $calendar = $this->relation->openCalendarOf((int) $context['provider_id']);

        return $this->place(
            $actor,
            $party,
            $calendar,
            $data,
            (int) $context['client_id'],
            AppointmentOrigin::Relation,
            fn (): int => $context['match_request_id'],
        );
    }

    /**
     * @param  BookingInput  $data
     * @param  Closure(): int  $matchRequest  identifiant de la demande de mise en relation, appelé
     *                                        dans la transaction une fois le créneau vérifié
     */
    public function place(User $actor, Party $party, Calendar $calendar, array $data, int $clientId, AppointmentOrigin $origin, Closure $matchRequest): Appointment
    {
        $appointment = DB::transaction(function () use ($party, $calendar, $data, $clientId, $origin, $matchRequest): Appointment {
            $calendar = Calendars::lock($calendar->id);

            if (! $calendar->is_enabled) {
                throw new ConflictHttpException('Ce prestataire ne prend pas de rendez-vous en ligne pour le moment.');
            }

            $type = $this->bookableType($calendar, $data['type'], $origin);
            $startsAt = CarbonImmutable::parse($data['starts_at'])->utc()->startOfMinute();
            $endsAt = $startsAt->addMinutes($type->duration_minutes);
            $location = $this->location($type, $party, $data);

            User::query()->whereKey($clientId)->lockForUpdate()->first();
            $provider = User::query()->findOrFail($calendar->user_id);

            $this->ledger->assertFree($calendar, $type->duration_minutes, $startsAt);

            $this->limits->assertProviderCapacity($provider, $party === Party::Provider);

            $matchRequestId = $matchRequest();

            if ($party === Party::Client) {
                $this->limits->assertClient($clientId, $matchRequestId);
            } else {
                $this->limits->assertProviderProposals($provider->id, $matchRequestId);
            }

            $status = match (true) {
                $party === Party::Provider => AppointmentStatus::Proposed,
                $type->auto_confirm && $origin === AppointmentOrigin::Relation => AppointmentStatus::Confirmed,
                default => AppointmentStatus::Requested,
            };
            $now = now();

            $appointment = new Appointment;
            $appointment->forceFill([
                'uuid' => (string) Str::uuid(),
                'match_request_id' => $matchRequestId,
                'calendar_id' => $calendar->id,
                'type_id' => $type->id,
                'provider_id' => $provider->id,
                'client_id' => $clientId,
                'origin' => $origin,
                'created_by' => $party,
                'status' => $status,
                'type_title' => $type->title,
                'duration_minutes' => $type->duration_minutes,
                'location_mode' => $type->location_mode,
                'location' => $location === [] ? null : $location,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $calendar->timezone,
                'expires_at' => $status->isPending() ? self::expiry($calendar, $startsAt) : null,
                'confirmed_at' => $status === AppointmentStatus::Confirmed ? $now : null,
                'scheduled_at' => $status === AppointmentStatus::Confirmed ? $now : null,
            ])->save();

            $this->ledger->hold($calendar, $appointment, HoldKind::Current, $startsAt, $endsAt);
            AppointmentNotes::add($appointment, $party, NoteKind::Booking, $data['note'] ?? null);

            $client = User::query()->find($clientId);

            if ($status === AppointmentStatus::Confirmed) {
                AppointmentNotification::sendTo($client, AppointmentNotice::Confirmed, $appointment);
                AppointmentNotification::sendTo($provider, AppointmentNotice::Confirmed, $appointment);
            } elseif ($status === AppointmentStatus::Requested) {
                AppointmentNotification::sendTo($provider, AppointmentNotice::RequestReceived, $appointment);
            } else {
                AppointmentNotification::sendTo($client, AppointmentNotice::ProposalReceived, $appointment);
            }

            return $appointment;
        });

        SecurityLog::record('appointments.'.$appointment->status->value, [
            'user_id' => $actor->id,
            'appointment' => $appointment->uuid,
            'origin' => $origin->value,
        ]);

        return $appointment;
    }

    /**
     * Fin d'attente d'une demande ou d'une proposition : 48 heures, ou plus tôt au délai de
     * prévenance avant le début (décision 55).
     */
    public static function expiry(Calendar $calendar, CarbonImmutable $startsAt): CarbonImmutable
    {
        return CarbonImmutable::now()
            ->addHours((int) config('appointments.request_expiration_hours'))
            ->min($startsAt->subMinutes($calendar->min_notice_minutes));
    }

    private function bookableType(Calendar $calendar, string $uuid, AppointmentOrigin $origin): AppointmentType
    {
        $type = $this->bookableTypes->of($calendar, $origin === AppointmentOrigin::PublicPage)->firstWhere('uuid', $uuid);

        if ($type === null || ($origin === AppointmentOrigin::PublicPage && $type->visibility !== TypeVisibility::Public)) {
            throw ValidationException::withMessages(['type' => 'Choisissez un type de rendez-vous proposé par ce prestataire.']);
        }

        return $type;
    }

    /**
     * Lieu chiffré du rendez-vous : détail du type (adresse du prestataire, lien de visio) ou
     * donné par le client (adresse, téléphone). Une proposition du prestataire pour un lieu que
     * le client doit donner attend la confirmation du client.
     *
     * @param  BookingInput  $data
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    private function location(AppointmentType $type, Party $party, array $data): array
    {
        $mode = $type->location_mode;

        if ($mode->detailsOnType()) {
            return $type->location_details === null ? [] : [$mode->detailKey() => $type->location_details];
        }

        if ($party === Party::Provider) {
            return [];
        }

        $value = $data[$mode->detailKey()] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw ValidationException::withMessages([$mode->detailKey() => $mode->detailKey() === 'phone'
                ? 'Indiquez le numéro auquel le prestataire vous appellera.'
                : 'Indiquez l\'adresse du rendez-vous.']);
        }

        return [$mode->detailKey() => trim($value)];
    }
}
