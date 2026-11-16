<?php

namespace App\Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Compliance\Actions\ManageCalendarFeed;
use App\Modules\Compliance\Models\CalendarFeed;
use App\Modules\Compliance\Models\Company;
use App\Modules\Compliance\Support\CalendarFeedToken;
use App\Modules\Compliance\Support\ComplianceCalendar;
use App\Modules\Compliance\Support\Disclaimer;
use App\Modules\Compliance\Support\Entitlements;
use App\Support\Locale;
use App\Support\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

/**
 * Abonnement au calendrier des échéances.
 *
 * - GET /compliance/calendar/{token}.ics : route PUBLIQUE (les agendas n'envoient pas de
 *   cookie), protégée par le seul jeton secret, limitée par abonnement et par IP
 *   (compliance-feed). Jeton inconnu, révoqué ou remplacé : 404, sans rien révéler.
 * - /me/compliance/calendar-feed : état, création ou remplacement (URL montrée une fois),
 *   révocation. Gratuit.
 */
class CalendarFeedController extends Controller
{
    public function __construct(private Entitlements $entitlements) {}

    public function feed(Request $request, string $token): Response
    {
        $feed = CalendarFeedToken::find($token);
        $user = $feed?->user;

        if ($feed === null || ! $user instanceof User || ! $user->hasAnyRole(['prestataire', 'entrepreneur'])) {
            // Au plus une entrée par IP et par heure : un jeton deviné au hasard ne remplit pas le journal.
            if (Cache::add('compliance.feed_invalid:'.hash('sha256', (string) $request->ip()), true, 3600)) {
                SecurityLog::record('compliance.calendar_feed_invalid', ['user_id' => null], 'warning');
            }

            abort(404);
        }

        if ($feed->last_accessed_at === null || $feed->last_accessed_at->lessThan(now()->subHour())) {
            $feed->forceFill(['last_accessed_at' => now()])->save();
        }

        $companies = Company::query()->where('user_id', $user->id)->orderBy('id')->get();
        $active = $companies->reject(fn (Company $company): bool => $this->entitlements->isLocked($company));
        $locale = in_array($user->locale, Locale::SUPPORTED, true) ? $user->locale : Locale::DEFAULT;

        return response(ComplianceCalendar::render($active, $companies->count(), $locale), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function show(Request $request): JsonResponse
    {
        Gate::authorize('use', Company::class);
        $feed = CalendarFeed::query()->where('user_id', self::user($request)->id)->first();

        return response()->json([
            'data' => self::state($feed),
            'disclaimer' => Disclaimer::forRequest($request),
        ]);
    }

    /**
     * Crée l'abonnement, ou le remplace (l'ancienne URL cesse de fonctionner). L'URL n'est
     * renvoyée que dans cette réponse.
     */
    public function store(Request $request, ManageCalendarFeed $manageCalendarFeed): JsonResponse
    {
        Gate::authorize('use', Company::class);
        $user = self::user($request);
        $url = $manageCalendarFeed->issue($user);

        return response()->json([
            'data' => [
                ...self::state(CalendarFeed::query()->where('user_id', $user->id)->first()),
                'url' => $url,
                'notice' => Locale::fromRequest($request) === 'en'
                    ? 'Keep this address private: anyone who has it can read the titles and dates of your deadlines. Regenerate it if it has been shared.'
                    : 'Gardez cette adresse privée : toute personne qui la connaît peut lire les titres et dates de vos échéances. Régénérez-la si elle a été partagée.',
            ],
            'disclaimer' => Disclaimer::forRequest($request),
        ], 201);
    }

    public function destroy(Request $request, ManageCalendarFeed $manageCalendarFeed): Response
    {
        Gate::authorize('use', Company::class);
        $manageCalendarFeed->revoke(self::user($request));

        return response()->noContent();
    }

    /**
     * @return array{active: bool, created_at: string|null, last_accessed_at: string|null}
     */
    private static function state(?CalendarFeed $feed): array
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
