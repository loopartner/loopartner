<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\HasPublishedProviderProfile;
use App\Modules\Appointments\Enums\Party;
use App\Modules\Appointments\Models\Calendar;
use App\Modules\Directory\Actions\GetMatchRequestContext;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Conditions d'un rendez-vous pris dans une mise en relation (décision 52), lues par l'Action
 * publique GetMatchRequestContext du module Directory :
 *
 * - demande inconnue ou dont l'utilisateur n'est pas participant : 404 ;
 * - demande pas encore acceptée : 409 ; blocage dans un sens ou dans l'autre : 403 ;
 * - l'autre partie a supprimé son compte : 409 ;
 * - calendrier du prestataire fermé, ou profil non publié (ou masqué) : 409.
 *
 * @phpstan-import-type MatchRequestContext from GetMatchRequestContext
 */
final class Relation
{
    public function __construct(
        private GetMatchRequestContext $contexts,
        private HasPublishedProviderProfile $hasPublishedProfile,
    ) {}

    /**
     * @return array{context: MatchRequestContext, party: Party}
     */
    public function forBooking(User $user, string $matchRequestUuid): array
    {
        $context = $this->contexts->handle($matchRequestUuid, $user);

        if ($context === null) {
            throw new NotFoundHttpException('Demande de mise en relation introuvable.');
        }

        $party = $context['provider_id'] === $user->id ? Party::Provider : Party::Client;

        if (! $context['accepted']) {
            throw new ConflictHttpException('Un rendez-vous se prend une fois la demande de mise en relation acceptée.');
        }

        if ($context['blocked']) {
            throw new AccessDeniedHttpException('Vous ne pouvez pas prendre de rendez-vous avec cet utilisateur.');
        }

        if ($context['provider_id'] === null || $context['client_id'] === null) {
            throw new ConflictHttpException('L\'autre participant n\'est plus inscrit.');
        }

        return ['context' => $context, 'party' => $party];
    }

    /**
     * Contexte pour la simple lecture (participants seulement, 404 sinon).
     *
     * @return array{context: MatchRequestContext, party: Party}
     */
    public function forReading(User $user, string $matchRequestUuid): array
    {
        $context = $this->contexts->handle($matchRequestUuid, $user);

        if ($context === null) {
            throw new NotFoundHttpException('Demande de mise en relation introuvable.');
        }

        return ['context' => $context, 'party' => $context['provider_id'] === $user->id ? Party::Provider : Party::Client];
    }

    /**
     * Calendrier ouvert d'un prestataire dont le profil est publié et visible.
     *
     * @throws ConflictHttpException
     */
    public function openCalendarOf(int $providerId): Calendar
    {
        $calendar = Calendar::query()->where('user_id', $providerId)->first();
        $provider = User::query()->find($providerId);

        if ($calendar === null || ! $calendar->is_enabled || $provider === null || ! $this->hasPublishedProfile->handle($provider)) {
            throw new ConflictHttpException('Ce prestataire ne prend pas de rendez-vous en ligne pour le moment.');
        }

        return $calendar;
    }
}
