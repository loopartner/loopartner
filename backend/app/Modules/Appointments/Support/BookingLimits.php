<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Limites des rendez-vous (décision 54 et précision du 2026-11-17), comptées sous verrou (ligne
 * du calendrier et ligne users du client) dans la transaction de la réservation :
 *
 * - client : demandes en attente, rendez-vous à venir, rendez-vous à venir par relation ;
 * - prestataire : propositions en attente par relation et au total, et rendez-vous à venir
 *   (appointments.upcoming_appointments de son plan).
 *
 * Toutes répondent 409, sauf la capacité du prestataire quand il propose lui-même un créneau
 * (403 premium_required si le Premium permet davantage). Le client n'apprend jamais le plan
 * du prestataire.
 */
final class BookingLimits
{
    public function __construct(private Plan $plan) {}

    public function assertClient(int $clientId, int $matchRequestId): void
    {
        $limits = (array) config('appointments.client');

        $pending = Appointment::query()
            ->where('client_id', $clientId)
            ->where('status', AppointmentStatus::Requested->value)
            ->count();

        if ($pending >= (int) $limits['max_pending_requests']) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d demandes de rendez-vous en attente de réponse.', (int) $limits['max_pending_requests']));
        }

        if (self::upcoming()->where('client_id', $clientId)->count() >= (int) $limits['max_upcoming']) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d rendez-vous à venir.', (int) $limits['max_upcoming']));
        }

        if (self::upcoming()->where('match_request_id', $matchRequestId)->count() >= (int) $limits['max_upcoming_per_relation']) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d rendez-vous à venir avec ce prestataire.', (int) $limits['max_upcoming_per_relation']));
        }
    }

    public function assertProviderProposals(int $providerId, int $matchRequestId): void
    {
        $limits = (array) config('appointments.provider');
        $pending = Appointment::query()->where('provider_id', $providerId)->where('status', AppointmentStatus::Proposed->value);

        if ((clone $pending)->where('match_request_id', $matchRequestId)->count() >= (int) $limits['max_pending_proposals_per_relation']) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d propositions de rendez-vous en attente dans cette relation.', (int) $limits['max_pending_proposals_per_relation']));
        }

        if ($pending->count() >= (int) $limits['max_pending_proposals']) {
            throw new ConflictHttpException(sprintf('Vous avez déjà %d propositions de rendez-vous en attente de réponse.', (int) $limits['max_pending_proposals']));
        }
    }

    /**
     * Capacité du prestataire (rendez-vous à venir, confirmés ou en attente).
     */
    public function assertProviderCapacity(User $provider, bool $proposedByProvider): void
    {
        $upcoming = self::upcoming()->where('provider_id', $provider->id)->count();

        if ($proposedByProvider) {
            $this->plan->assertBelow($provider, 'upcoming_appointments', $upcoming, 'upcoming_appointments', 'Vous avez déjà %d rendez-vous à venir.');

            return;
        }

        $limit = $this->plan->limit($provider, 'upcoming_appointments');

        if ($limit !== null && $upcoming >= $limit) {
            throw new ConflictHttpException('Ce prestataire ne peut plus recevoir de rendez-vous pour le moment.');
        }
    }

    /**
     * @return Builder<Appointment>
     */
    private static function upcoming(): Builder
    {
        return Appointment::query()
            ->whereIn('status', AppointmentStatus::activeValues())
            ->where('starts_at', '>', now());
    }
}
