<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Models\CalendarFeed;
use App\Modules\Compliance\Support\CalendarFeedToken;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * URL privée d'abonnement au calendrier : création, remplacement (l'ancienne URL cesse
 * aussitôt de fonctionner) et révocation. L'URL complète n'est renvoyée qu'une fois, à la
 * création ou au remplacement ; elle n'est jamais stockée ni journalisée.
 */
class ManageCalendarFeed
{
    /**
     * @return string l'URL d'abonnement (à montrer une seule fois)
     */
    public function issue(User $user): string
    {
        return DB::transaction(function () use ($user): string {
            $token = CalendarFeedToken::generate();
            $feed = CalendarFeed::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $event = $feed === null ? 'compliance.calendar_feed_created' : 'compliance.calendar_feed_regenerated';

            $feed ??= new CalendarFeed;
            $feed->forceFill([
                'user_id' => $user->id,
                'selector' => $token['selector'],
                'verifier_hash' => $token['hash'],
                'last_accessed_at' => null,
            ])->save();

            SecurityLog::record($event, ['user_id' => $user->id]);

            return CalendarFeedToken::url($token['token']);
        });
    }

    /**
     * @throws NotFoundHttpException
     */
    public function revoke(User $user): void
    {
        $deleted = CalendarFeed::query()->where('user_id', $user->id)->delete();

        if ($deleted === 0) {
            throw new NotFoundHttpException('Aucun abonnement au calendrier.');
        }

        SecurityLog::record('compliance.calendar_feed_revoked', ['user_id' => $user->id]);
    }
}
