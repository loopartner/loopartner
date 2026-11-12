<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ServiceCategory;
use App\Modules\Events\Http\Requests\SearchEventsRequest;
use App\Modules\Events\Http\Resources\PublicEventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\IcsCalendar;
use App\Modules\Events\Support\OrganizerDirectory;
use App\Support\Database\Like;
use App\Support\Pagination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Agenda public : événements publiés et validés par un admin. Un événement en brouillon, en
 * attente, refusé, annulé ou inconnu donne 404, sans révéler lequel de ces cas s'applique.
 */
class PublicEventController extends Controller
{
    private const RELATIONS = ['categories', 'cover', 'user.roles'];

    /**
     * Événements publiés à venir ou en cours, triés par date de début.
     */
    public function index(SearchEventsRequest $request): AnonymousResourceCollection
    {
        $query = Event::query()->live()->with(self::RELATIONS);

        if ($request->filled('from')) {
            $query->where('ends_at', '>=', CarbonImmutable::parse($request->string('from')->toString(), 'UTC')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('starts_at', '<', CarbonImmutable::parse($request->string('to')->toString(), 'UTC')->addDay()->startOfDay());
        }

        foreach (['type', 'format', 'country', 'language'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->string($filter)->toString());
            }
        }

        if ($request->filled('city')) {
            Like::whereEquals($query->getQuery(), 'city', $request->string('city')->toString());
        }

        if ($request->filled('category')) {
            $ids = ServiceCategory::idsWithDescendants($request->string('category')->toString());
            $query->whereHas('categories', fn (Builder $categories) => $categories->whereIn('service_categories.id', $ids));
        }

        if ($request->filled('free')) {
            $query->where('is_free', $request->boolean('free'));
        }

        if ($request->filled('q')) {
            Like::whereContains($query->getQuery(), ['title', 'description', 'city'], $request->string('q')->toString());
        }

        $events = $query
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $organizers = OrganizerDirectory::for($events->getCollection());

        return PublicEventResource::collection(
            $events->through(fn (Event $event): PublicEventResource => new PublicEventResource($event, $organizers)),
        );
    }

    public function show(string $slug): PublicEventResource
    {
        $event = self::find($slug)->load(self::RELATIONS);

        return new PublicEventResource($event, OrganizerDirectory::for([$event]));
    }

    /**
     * Fichier calendrier public : sans lien de connexion, adresse masquée si elle est réservée
     * aux inscrits.
     */
    public function calendar(string $slug): Response
    {
        $event = self::find($slug);

        return self::calendarResponse($event, forRegistrant: false);
    }

    public static function calendarResponse(Event $event, bool $forRegistrant): Response
    {
        return response(IcsCalendar::render($event, $forRegistrant), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.$event->slug.'.ics"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function find(string $slug): Event
    {
        $event = Event::query()->publiclyVisible()->where('slug', $slug)->first();
        abort_if($event === null, 404, 'Événement introuvable.');

        return $event;
    }
}
