<?php

namespace App\Modules\Compliance\Actions;

use App\Models\User;
use App\Modules\Compliance\Models\CalendarFeed;
use App\Modules\Compliance\Support\CalendarFeedToken;
use App\Support\SecurityLog;
use Illuminate\Database\UniqueConstraintViolationException;
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
        // Verrou de la ligne users (elle existe toujours) : un verrou sur un abonnement encore
        // absent ne protège rien et fait s'interbloquer deux créations simultanées sous InnoDB.
        // Si l'index unique user_id refuse malgré tout l'insertion, la seconde tentative
        // régénère l'abonnement créé entre-temps.
        return retry(2, fn (): string => DB::transaction(function () use ($user): string {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $token = CalendarFeedToken::generate();
            $feed = CalendarFeed::query()->where('user_id', $user->id)->first();
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
        }), 0, fn (\Throwable $e): bool => $e instanceof UniqueConstraintViolationException);
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
