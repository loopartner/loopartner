<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Actions\RegisterForEvent;
use App\Modules\Events\Actions\UnregisterFromEvent;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Http\Requests\ListMyRegistrationsRequest;
use App\Modules\Events\Http\Resources\MyRegistrationResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\OrganizerDirectory;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Inscriptions de l'utilisateur connecté, avec un email vérifié. L'inscrit trouve ici le lien
 * de connexion et l'adresse complète ; une inscription qui n'est pas la sienne donne 404.
 */
class RegistrationController extends Controller
{
    private const EVENT_RELATIONS = ['event.categories', 'event.cover', 'event.user.roles'];

    /**
     * POST /events/{slug}/registration. Événement non publié ou inconnu : 404 ; organisateur ou
     * blocage : 403 ; payant, complet, commencé ou déjà inscrit : 409.
     */
    public function store(Request $request, string $slug, RegisterForEvent $registerForEvent): JsonResponse
    {
        $user = self::user($request);
        $event = self::publicEvent($slug);
        Gate::authorize('register', $event);

        $registration = $registerForEvent->handle($user, $event);

        return (new MyRegistrationResource($registration->load(self::EVENT_RELATIONS), OrganizerDirectory::for([$registration->event])))
            ->response()
            ->setStatusCode(201);
    }

    public function destroy(Request $request, string $slug, UnregisterFromEvent $unregisterFromEvent): Response
    {
        $unregisterFromEvent->handle(self::user($request), self::publicEvent($slug));

        return response()->noContent();
    }

    public function index(ListMyRegistrationsRequest $request): AnonymousResourceCollection
    {
        $past = $request->validated('when') === 'past';

        $registrations = EventRegistration::query()
            ->select('event_registrations.*')
            ->join('events', 'events.id', '=', 'event_registrations.event_id')
            ->where('event_registrations.user_id', self::user($request)->id)
            ->where('event_registrations.status', RegistrationStatus::Registered->value)
            ->where('events.ends_at', $past ? '<=' : '>', now())
            ->orderBy('events.starts_at', $past ? 'desc' : 'asc')
            ->orderBy('event_registrations.id')
            ->with(self::EVENT_RELATIONS)
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $organizers = OrganizerDirectory::for($registrations->getCollection()->map(fn (EventRegistration $registration): Event => $registration->event));

        return MyRegistrationResource::collection(
            $registrations->through(fn (EventRegistration $registration): MyRegistrationResource => new MyRegistrationResource($registration, $organizers)),
        );
    }

    public function show(Request $request, string $slug): MyRegistrationResource
    {
        $registration = self::ownRegistration($request, $slug)->load([...self::EVENT_RELATIONS, 'event.announcements']);

        return (new MyRegistrationResource($registration, OrganizerDirectory::for([$registration->event])))->withAnnouncements();
    }

    /**
     * Fichier calendrier de l'inscrit : avec le lien de connexion et l'adresse complète.
     */
    public function calendar(Request $request, string $slug): Response
    {
        return PublicEventController::calendarResponse(self::ownRegistration($request, $slug)->event, forRegistrant: true);
    }

    private static function ownRegistration(Request $request, string $slug): EventRegistration
    {
        $registration = EventRegistration::query()
            ->where('user_id', self::user($request)->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->whereHas('event', fn (Builder $query) => $query->where('slug', $slug))
            ->with('event')
            ->first();

        abort_if($registration === null, 404, 'Inscription introuvable.');

        return $registration;
    }

    private static function publicEvent(string $slug): Event
    {
        $event = Event::query()->publiclyVisible()->where('slug', $slug)->first();
        abort_if($event === null, 404, 'Événement introuvable.');

        return $event;
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
