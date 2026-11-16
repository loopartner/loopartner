<?php

namespace App\Modules\Moderation\Actions;

use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\NoticePriority;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Notifications\ModerationDigestNotification;
use App\Modules\Moderation\Support\Admins;
use Illuminate\Support\Facades\Cache;

/**
 * Récapitulatif aux admins avec 2FA (tâche moderation:notify-moderators, toutes les 5 minutes) :
 * au plus un email par période (moderation.digest_minutes, 30), et seulement si un signalement ou
 * un recours est arrivé depuis le précédent. Des nombres seulement (en cours, urgents, en retard,
 * recours) : aucun contenu. Les signalements urgents ont en plus leur alerte immédiate.
 */
class NotifyModerators
{
    public const LAST_SENT_KEY = 'moderation.digest.last_sent_at';

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
        $period = (int) config('moderation.digest_minutes') * 60;

        if ($lastSent !== null && now()->getTimestamp() - $lastSent < $period) {
            return 0;
        }

        $newest = max(
            Notice::query()->where('status', NoticeStatus::Open->value)->max('created_at') ?? '',
            Appeal::query()->where('status', AppealStatus::Pending->value)->max('created_at') ?? '',
        );

        if ($newest === '' || ($lastSent !== null && strtotime((string) $newest.' UTC') <= $lastSent)) {
            return 0;
        }

        $open = Notice::query()->where('status', NoticeStatus::Open->value);
        $counts = [
            'open' => (clone $open)->count(),
            'urgent' => (clone $open)->where('priority', NoticePriority::Urgent->value)->count(),
            'overdue' => (clone $open)->where('due_at', '<', now())->count(),
            'appeals' => Appeal::query()->where('status', AppealStatus::Pending->value)->count(),
        ];
        $admins = Admins::recipients();

        foreach ($admins as $admin) {
            ModerationDigestNotification::sendTo($admin, new ModerationDigestNotification($counts));
        }

        Cache::forever(self::LAST_SENT_KEY, now()->getTimestamp());

        return $admins->count();
    }
}
