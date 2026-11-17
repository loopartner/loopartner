<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\AppointmentNote;
use App\Modules\Appointments\Models\AppointmentType;
use App\Modules\Directory\Actions\GetMatchRequestContext;
use App\Support\Locale;

/**
 * Réponse d'un rendez-vous pour l'une de ses parties (décision 53). Aucun identifiant interne,
 * aucun email ni téléphone d'un compte.
 *
 * - counterpart : pour le client, le résumé public du prestataire (GetProviderSummaries) ;
 *   pour le prestataire, le type de compte et le pays du client, et son **nom seulement une
 *   fois la demande de mise en relation acceptée** (GetMatchRequestContext). Compte supprimé :
 *   {"deleted": true}.
 * - location : seulement une fois le rendez-vous confirmé, et jusqu'à son effacement.
 * - notes : texte des deux parties ; masqué par la modération ou effacé : body null.
 *
 * @phpstan-import-type ProviderSummary from GetProviderSummaries
 */
final class AppointmentPresenter
{
    public function __construct(
        private GetProviderSummaries $providerSummaries,
        private GetMatchRequestContext $matchRequests,
    ) {}

    /**
     * @param  iterable<Appointment>  $appointments
     * @return list<array<string, mixed>>
     */
    public function many(iterable $appointments, User $viewer, string $locale): array
    {
        $appointments = collect($appointments);
        $providerIds = $appointments->pluck('provider_id')->filter()->unique()->values()->all();
        $summaries = $this->providerSummaries->handle(array_map('intval', $providerIds));

        return $appointments->map(fn (Appointment $appointment): array => $this->present($appointment, $viewer, $locale, $summaries))->values()->all();
    }

    /**
     * @param  array<int, ProviderSummary>|null  $summaries
     * @return array<string, mixed>
     */
    public function present(Appointment $appointment, User $viewer, string $locale, ?array $summaries = null): array
    {
        $party = $appointment->partyOf($viewer) ?? Party::Client;
        $appointment->loadMissing(['type', 'notes', 'client.roles']);
        $context = $this->matchRequests->forId($appointment->match_request_id, $viewer);

        return [
            'uuid' => $appointment->uuid,
            'status' => $appointment->status->value,
            'viewer_role' => $party->value,
            'awaiting_me' => $appointment->awaitedParty() === $party,
            'origin' => $appointment->origin->value,
            'created_by_me' => $appointment->created_by === $party,
            'match_request' => $context['uuid'] ?? null,
            'type' => [
                'uuid' => $appointment->type?->uuid,
                'title' => AppointmentLocation::typeTitle($appointment, $locale),
                'duration_minutes' => $appointment->duration_minutes,
                'location_mode' => $appointment->location_mode->value,
            ],
            ...SlotPresenter::present($appointment->starts_at, $appointment->ends_at, $appointment->timezone),
            'timezone' => $appointment->timezone,
            'version' => $appointment->version,
            'location' => AppointmentLocation::visibleTo($appointment, $party),
            'location_erased' => $appointment->location_erased_at !== null,
            'counterpart' => $party === Party::Client ? $this->provider($appointment, $summaries) : $this->client($appointment, $context),
            'expires_at' => $appointment->expires_at?->toIso8601ZuluString(),
            'pending_change' => $appointment->change_starts_at === null || $appointment->change_ends_at === null ? null : [
                ...SlotPresenter::present($appointment->change_starts_at, $appointment->change_ends_at, $appointment->timezone),
                'proposed_by_me' => $appointment->change_by === $party,
                'expires_at' => $appointment->change_expires_at?->toIso8601ZuluString(),
            ],
            'reschedules_left' => max(0, (int) config('appointments.max_reschedules') - $appointment->reschedule_count),
            'cancellation' => $appointment->cancelled_by === null ? null : [
                'by' => $appointment->cancelled_by->value,
                'reason' => $appointment->cancellation_reason?->value,
            ],
            'notes' => $appointment->notes->map(fn (AppointmentNote $note): array => [
                'uuid' => $note->uuid,
                'kind' => $note->kind->value,
                'mine' => $note->author_side === $party,
                'body' => $note->moderation_restricted_at !== null || $note->erased_at !== null ? null : $note->body,
                'restricted' => $note->moderation_restricted_at !== null,
                'created_at' => $note->created_at?->toIso8601ZuluString(),
            ])->values()->all(),
            'confirmed_at' => $appointment->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $appointment->cancelled_at?->toIso8601ZuluString(),
            'created_at' => $appointment->created_at?->toIso8601ZuluString(),
            'updated_at' => $appointment->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Type de rendez-vous tel qu'un client le voit (relation, page publique) : jamais le
     * détail du lieu.
     *
     * @return array{uuid: string, title: string, description: string|null, duration_minutes: int, location_mode: string}
     */
    public static function publicType(AppointmentType $type): array
    {
        return [
            'uuid' => $type->uuid,
            'title' => $type->title,
            'description' => $type->description,
            'duration_minutes' => $type->duration_minutes,
            'location_mode' => $type->location_mode->value,
        ];
    }

    /**
     * @param  array<int, ProviderSummary>|null  $summaries
     * @return array<string, mixed>
     */
    private function provider(Appointment $appointment, ?array $summaries): array
    {
        if ($appointment->provider_id === null) {
            return ['deleted' => true];
        }

        $summaries ??= $this->providerSummaries->handle([$appointment->provider_id]);
        $summary = $summaries[$appointment->provider_id] ?? null;

        return $summary === null ? ['deleted' => true] : ['type' => 'provider', ...$summary];
    }

    /**
     * @param  array<string, mixed>|null  $context  contexte de la demande (GetMatchRequestContext)
     * @return array<string, mixed>
     */
    private function client(Appointment $appointment, ?array $context): array
    {
        $client = $appointment->client;

        if ($client === null) {
            return ['deleted' => true];
        }

        $accountType = $client->hasRole(AccountType::Entrepreneur->value) ? AccountType::Entrepreneur->value : AccountType::Particulier->value;

        return [
            'type' => 'client',
            'account_type' => $context['client_role'] ?? $accountType,
            'country' => $client->country,
            'name' => $context['client_name'] ?? null,
        ];
    }

    public static function locale(?string $locale): string
    {
        return in_array($locale, Locale::SUPPORTED, true) ? (string) $locale : Locale::DEFAULT;
    }
}
