<?php

namespace App\Http\Controllers;

use App\Models\AgendaFeed;
use App\Models\User;
use App\Support\Agenda\Agenda;
use App\Support\Agenda\AgendaCalendar;
use App\Support\Calendar\FeedToken;
use App\Support\Locale;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Abonnement unique à l'agenda (lot 4).
 *
 * - GET /agenda/{token}.ics : route PUBLIQUE (les agendas n'envoient pas de cookie), protégée
 *   par le seul jeton secret (FeedToken), limitée par abonnement et par IP (agenda-feed).
 *   **Titres et dates seulement**, éléments confirmés ou publiés, du mois écoulé aux douze
 *   prochains mois. Jeton inconnu, révoqué ou remplacé : 404, journalisé au plus une fois par
 *   heure et par IP, jamais le jeton.
 * - /me/agenda/feed : état, création ou remplacement (l'URL n'est montrée qu'une fois ;
 *   l'ancienne cesse aussitôt de fonctionner), révocation.
 */
class AgendaFeedController extends Controller
{
    public function feed(Request $request, string $token): Response
    {
        $feed = AgendaFeed::query()->where('selector', FeedToken::selector($token))->first();
        $user = FeedToken::matches($feed?->verifier_hash, $token) ? $feed?->user : null;

        if ($feed === null || ! $user instanceof User) {
            if (Cache::add('agenda.feed_invalid:'.hash('sha256', (string) $request->ip()), true, 3600)) {
                SecurityLog::record('agenda.feed_invalid', ['user_id' => null], 'warning');
            }

            abort(404);
        }

        if ($feed->last_accessed_at === null || $feed->last_accessed_at->lessThan(now()->subHour())) {
            $feed->forceFill(['last_accessed_at' => now()])->save();
        }

        $locale = in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;
        $now = CarbonImmutable::now()->utc();
        $agenda = Agenda::for($user, $now->subDays((int) config('agenda.feed_past_days'))->startOfDay(), $now->addMonths((int) config('agenda.feed_future_months')), $locale, true);

        return response(AgendaCalendar::render($agenda['items'], $locale), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => self::state(AgendaFeed::query()->where('user_id', self::user($request)->id)->first())]);
    }

    /**
     * Crée l'abonnement, ou le remplace. L'URL n'est renvoyée que dans cette réponse ; elle n'est
     * jamais stockée ni journalisée.
     */
    public function store(Request $request): JsonResponse
    {
        $user = self::user($request);

        $url = DB::transaction(function () use ($user): string {
            $token = FeedToken::generate();
            $feed = AgendaFeed::query()->where('user_id', $user->id)->lockForUpdate()->first();
            $event = $feed === null ? 'agenda.feed_created' : 'agenda.feed_regenerated';

            $feed ??= new AgendaFeed;
            $feed->forceFill([
                'user_id' => $user->id,
                'selector' => $token['selector'],
                'verifier_hash' => $token['hash'],
                'last_accessed_at' => null,
            ])->save();

            SecurityLog::record($event, ['user_id' => $user->id]);

            return rtrim((string) config('app.url'), '/').'/api/v1/agenda/'.$token['token'].'.ics';
        });

        return response()->json([
            'data' => [
                ...self::state(AgendaFeed::query()->where('user_id', $user->id)->first()),
                'url' => $url,
                'notice' => Locale::fromRequest($request) === 'en'
                    ? 'Keep this address private: anyone who has it can read the titles and dates in your calendar. Regenerate it if it has been shared.'
                    : 'Gardez cette adresse privée : toute personne qui la connaît peut lire les titres et les dates de votre agenda. Régénérez-la si elle a été partagée.',
            ],
        ], 201);
    }

    public function destroy(Request $request): Response
    {
        $user = self::user($request);
        abort_if(AgendaFeed::query()->where('user_id', $user->id)->delete() === 0, 404, 'Aucun abonnement à l\'agenda.');
        SecurityLog::record('agenda.feed_revoked', ['user_id' => $user->id]);

        return response()->noContent();
    }

    /**
     * @return array{active: bool, created_at: string|null, last_accessed_at: string|null}
     */
    private static function state(?AgendaFeed $feed): array
    {
        return [
            'active' => $feed !== null,
            'created_at' => $feed?->updated_at?->toIso8601ZuluString(),
            'last_accessed_at' => $feed?->last_accessed_at?->toIso8601ZuluString(),
        ];
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
