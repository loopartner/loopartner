<?php

namespace App\Modules\Events\Actions;

use App\Models\User;
use App\Modules\Events\Notifications\ModerationDigestNotification;
use App\Modules\Events\Support\ModerationQueue;
use App\Modules\Events\Support\Organizers;
use Illuminate\Support\Facades\Cache;

/**
 * Récapitulatif de modération envoyé aux admins (tâche events:notify-moderators, toutes les
 * 5 minutes) : au plus un email par période (config events.moderation_digest_minutes, 30), et
 * seulement si un élément est arrivé depuis le précédent. Les soumissions sont donc regroupées.
 *
 * La date du dernier envoi est gardée dans le cache (driver database en production). Un verrou
 * empêche deux exécutions simultanées d'envoyer deux fois.
 */
class NotifyModerators
{
    public const LAST_SENT_KEY = 'events.moderation_digest.last_sent_at';

    /**
     * @return int nombre d'admins prévenus
     */
    public function handle(): int
    {
        $lock = Cache::lock(self::LAST_SENT_KEY.':lock', 120);

        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->send();
        } finally {
            $lock->release();
        }
    }

    private function send(): int
    {
        $lastSent = Cache::get(self::LAST_SENT_KEY);
        $lastSent = is_int($lastSent) ? $lastSent : null;
        $period = (int) config('events.moderation_digest_minutes') * 60;

        if ($lastSent !== null && now()->getTimestamp() - $lastSent < $period) {
            return 0;
        }

        $newest = ModerationQueue::newestSubmittedAt();

        if ($newest === null || ($lastSent !== null && $newest->getTimestamp() <= $lastSent)) {
            return 0;
        }

        $counts = ModerationQueue::counts();
        $admins = User::role(Organizers::ADMIN)->whereNotNull('email_verified_at')->get();

        foreach ($admins as $admin) {
            ModerationDigestNotification::sendTo($admin, new ModerationDigestNotification($counts));
        }

        Cache::forever(self::LAST_SENT_KEY, now()->getTimestamp());

        return $admins->count();
    }
}
