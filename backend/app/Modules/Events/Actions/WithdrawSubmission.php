<?php

namespace App\Modules\Events\Actions;

use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retire un événement de la file de validation : il redevient un brouillon modifiable.
 */
class WithdrawSubmission
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(Event $event): Event
    {
        return DB::transaction(function () use ($event): Event {
            $event = Event::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();

            if ($event->status !== EventStatus::PendingReview) {
                throw new ConflictHttpException('Cet événement n\'est pas en attente de validation.');
            }

            $event->forceFill([
                'status' => EventStatus::Draft,
                'submitted_at' => null,
            ])->save();

            SecurityLog::record('events.submission_withdrawn', ['event' => $event->uuid]);

            return $event;
        });
    }
}
