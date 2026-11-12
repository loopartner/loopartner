<?php

namespace App\Modules\Events\Support;

use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Plafonds par organisateur (config/events.php) : événements en attente de validation, et
 * événements publiés pas encore terminés. À appeler dans une transaction, après lock() :
 * la ligne users de l'organisateur est verrouillée pendant le comptage, deux soumissions
 * simultanées ne peuvent pas dépasser le plafond.
 */
final class OrganizerQuota
{
    public static function lock(int $organizerId): void
    {
        User::query()->whereKey($organizerId)->lockForUpdate()->first();
    }

    /**
     * @throws ConflictHttpException
     */
    public static function assertCanSubmit(int $organizerId): void
    {
        $max = (int) config('events.max_pending_review_per_organizer');

        $pending = Event::query()
            ->where('user_id', $organizerId)
            ->where('status', EventStatus::PendingReview->value)
            ->count();

        if ($pending >= $max) {
            SecurityLog::record('events.pending_limit_reached', ['user_id' => $organizerId], 'warning');

            throw new ConflictHttpException(sprintf(
                'Vous avez déjà %d événements en attente de validation : attendez la décision de l\'équipe avant d\'en soumettre un autre.',
                $max,
            ));
        }

        self::assertCanPublish($organizerId);
    }

    /**
     * @throws ConflictHttpException
     */
    public static function assertCanPublish(int $organizerId): void
    {
        $max = (int) config('events.max_upcoming_published_per_organizer');

        $upcoming = Event::query()->where('user_id', $organizerId)->live()->count();

        if ($upcoming >= $max) {
            throw new ConflictHttpException(sprintf(
                'Vous avez déjà %d événements publiés à venir : c\'est le maximum.',
                $max,
            ));
        }
    }
}
