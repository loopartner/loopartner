<?php

namespace App\Modules\Blog\Actions;

use App\Models\User;
use App\Modules\Blog\Notifications\ModerationDigestNotification;
use App\Modules\Blog\Support\BlogModeration;
use App\Modules\Blog\Support\ModerationQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Récapitulatif de modération du Blog (tâche blog:notify-moderators, toutes les 5 minutes) :
 * au plus un email par période (config blog.moderation_digest_minutes, 30), et seulement si un
 * élément est arrivé depuis le précédent. Envoyé aux rédacteurs et admins dont l'email est
 * vérifié et la double authentification confirmée : les seuls qui peuvent modérer.
 *
 * La date du dernier envoi est gardée dans le cache (driver database en production). Un verrou
 * empêche deux exécutions simultanées d'envoyer deux fois.
 */
class NotifyModerators
{
    public const LAST_SENT_KEY = 'blog.moderation_digest.last_sent_at';

    /**
     * @return int nombre de modérateurs prévenus
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
        $period = (int) config('blog.moderation_digest_minutes') * 60;

        if ($lastSent !== null && now()->getTimestamp() - $lastSent < $period) {
            return 0;
        }

        $newest = ModerationQueue::newestSubmittedAt();

        if ($newest === null || ($lastSent !== null && $newest->getTimestamp() <= $lastSent)) {
            return 0;
        }

        $counts = ModerationQueue::counts();
        $moderators = User::role(BlogModeration::ROLES)
            ->whereNotNull('email_verified_at')
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->get();

        foreach ($moderators as $moderator) {
            ModerationDigestNotification::sendTo($moderator, new ModerationDigestNotification($counts));
        }

        Cache::forever(self::LAST_SENT_KEY, now()->getTimestamp());

        return $moderators->count();
    }
}
