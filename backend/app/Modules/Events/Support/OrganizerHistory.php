<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Directory\Actions\GetUserModerationSignals;
use App\Modules\Events\Enums\AnnouncementStatus;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RevisionStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRevision;

/**
 * Historique d'un organisateur présenté aux admins pendant la modération : ancienneté du compte,
 * type de compte, profil public (prestataire), événements par statut, révisions et annonces
 * refusées, et signaux de la messagerie (signalements reçus, blocages subis) via l'Action
 * publique du module Directory.
 *
 * Que des nombres et le profil public : ni email, ni nom d'utilisateur, ni identifiant interne.
 */
class OrganizerHistory
{
    public function __construct(
        private GetProviderSummaries $getProviderSummaries,
        private GetUserModerationSignals $getUserModerationSignals,
    ) {}

    /**
     * @return array<string, mixed>|null null si le compte a été supprimé
     */
    public function for(?User $organizer): ?array
    {
        if ($organizer === null) {
            return null;
        }

        $events = Event::query()
            ->where('user_id', $organizer->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $revisions = EventRevision::query()
            ->where('user_id', $organizer->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $announcements = EventAnnouncement::query()
            ->where('user_id', $organizer->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $count = fn ($counts, string $status): int => (int) ($counts[$status] ?? 0);

        return [
            'account' => [
                'account_type' => Organizers::accountType($organizer),
                'age_days' => (int) $organizer->created_at?->diffInDays(now()),
                'email_verified' => $organizer->hasVerifiedEmail(),
            ],
            'provider' => Organizers::isProvider($organizer)
                ? ($this->getProviderSummaries->handle([$organizer->id])[$organizer->id] ?? null)
                : null,
            'events' => [
                'draft' => $count($events, EventStatus::Draft->value),
                'pending_review' => $count($events, EventStatus::PendingReview->value),
                'published' => $count($events, EventStatus::Published->value),
                'past' => $count($events, EventStatus::Past->value),
                'rejected' => $count($events, EventStatus::Rejected->value),
                'cancelled' => $count($events, EventStatus::Cancelled->value),
                'cancelled_by_admin' => Event::query()
                    ->where('user_id', $organizer->id)
                    ->where('cancelled_by', CancelledBy::Admin->value)
                    ->count(),
            ],
            'revisions' => [
                'approved' => $count($revisions, RevisionStatus::Approved->value),
                'rejected' => $count($revisions, RevisionStatus::Rejected->value),
            ],
            'announcements' => [
                'published' => $count($announcements, AnnouncementStatus::Published->value),
                'rejected' => $count($announcements, AnnouncementStatus::Rejected->value),
            ],
            'messaging' => $this->getUserModerationSignals->handle($organizer->id),
        ];
    }
}
