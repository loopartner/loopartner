<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Appointments\Models\AvailabilityException;
use App\Modules\Appointments\Models\AvailabilityRule;
use App\Modules\Appointments\Models\Calendar;

/**
 * Données personnelles du module Appointments pour l'export général du lot 11 (décision 59 ;
 * droit d'accès et portabilité, RGPD art. 15 et 20). **Interne : aucune route ne l'expose
 * encore** ; le lot 11 la branchera sur le contrat du noyau prévu (PersonalDataSource).
 *
 * Contenu : ce que l'utilisateur a fourni (réglages, types, plages, exceptions, ses textes, le
 * lieu qu'il a donné) et ses rendez-vous (dates, type, statut, son rôle). Jamais le texte ni
 * l'identité de l'autre partie, ni identifiant interne : uuids seulement. Données chiffrées
 * en base rendues en clair (l'export est remis à la personne elle-même).
 */
final class AppointmentsPersonalData
{
    /**
     * @return array{calendar: array<string, mixed>|null, appointment_types: list<array<string, mixed>>, availability_rules: list<array<string, mixed>>, availability_exceptions: list<array<string, mixed>>, appointments: list<array<string, mixed>>}
     */
    public static function for(User $user): array
    {
        $calendar = Calendar::query()->where('user_id', $user->id)->first();

        return [
            'calendar' => $calendar === null ? null : [
                'timezone' => $calendar->timezone,
                'is_enabled' => $calendar->is_enabled,
                'public_page_enabled' => $calendar->public_page_enabled,
                'slot_step_minutes' => $calendar->slot_step_minutes,
                'buffer_minutes' => $calendar->buffer_minutes,
                'min_notice_minutes' => $calendar->min_notice_minutes,
                'horizon_days' => $calendar->horizon_days,
                'reminder_offsets' => $calendar->reminder_offsets,
            ],
            'appointment_types' => $calendar === null ? [] : AppointmentType::query()->where('calendar_id', $calendar->id)->orderBy('id')->get()
                ->map(fn (AppointmentType $type): array => [
                    'uuid' => $type->uuid,
                    'title' => $type->title,
                    'description' => $type->description,
                    'duration_minutes' => $type->duration_minutes,
                    'location_mode' => $type->location_mode->value,
                    'location_details' => $type->location_details,
                    'visibility' => $type->visibility->value,
                    'auto_confirm' => $type->auto_confirm,
                    'is_active' => $type->is_active,
                    'created_at' => $type->created_at?->toIso8601ZuluString(),
                ])->values()->all(),
            'availability_rules' => $calendar === null ? [] : AvailabilityRule::query()->where('calendar_id', $calendar->id)->orderBy('weekday')->orderBy('start_minute')->get()
                ->map(fn (AvailabilityRule $rule): array => [
                    'weekday' => $rule->weekday,
                    'start' => TimeOfDay::format($rule->start_minute),
                    'end' => TimeOfDay::format($rule->end_minute),
                ])->values()->all(),
            'availability_exceptions' => $calendar === null ? [] : AvailabilityException::query()->where('calendar_id', $calendar->id)->orderBy('date')->get()
                ->map(fn (AvailabilityException $exception): array => [
                    'date' => $exception->date->toDateString(),
                    'kind' => $exception->kind->value,
                    'start' => $exception->start_minute === null ? null : TimeOfDay::format($exception->start_minute),
                    'end' => $exception->end_minute === null ? null : TimeOfDay::format($exception->end_minute),
                ])->values()->all(),
            'appointments' => Appointment::query()
                ->where(fn ($query) => $query->where('client_id', $user->id)->orWhere('provider_id', $user->id))
                ->with('notes')
                ->orderBy('starts_at')
                ->get()
                ->map(fn (Appointment $appointment): array => self::appointment($appointment, $user))
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function appointment(Appointment $appointment, User $user): array
    {
        $party = $appointment->partyOf($user) ?? Party::Client;
        $mode = $appointment->location_mode;
        $givenByUser = $party === Party::Client ? $mode->detailsFromClient() : $mode->detailsOnType();

        return [
            'uuid' => $appointment->uuid,
            'role' => $party->value,
            'status' => $appointment->status->value,
            'origin' => $appointment->origin->value,
            'type_title' => $appointment->type_title,
            'duration_minutes' => $appointment->duration_minutes,
            'location_mode' => $mode->value,
            'location_given' => $givenByUser && $appointment->location !== null ? $appointment->location->getArrayCopy() : null,
            'starts_at' => $appointment->starts_at->toIso8601ZuluString(),
            'ends_at' => $appointment->ends_at->toIso8601ZuluString(),
            'timezone' => $appointment->timezone,
            'cancellation' => $appointment->cancelled_by === null ? null : ['by' => $appointment->cancelled_by->value, 'reason' => $appointment->cancellation_reason?->value],
            'my_notes' => $appointment->notes
                ->filter(fn (AppointmentNote $note): bool => $note->author_side === $party && $note->body !== null)
                ->map(fn (AppointmentNote $note): array => ['kind' => $note->kind->value, 'body' => $note->body, 'created_at' => $note->created_at?->toIso8601ZuluString()])
                ->values()
                ->all(),
            'created_at' => $appointment->created_at?->toIso8601ZuluString(),
        ];
    }
}
