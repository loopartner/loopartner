<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Actions\CancelEvent;
use App\Modules\Events\Actions\CreateEvent;
use App\Modules\Events\Actions\DeleteEvent;
use App\Modules\Events\Actions\SubmitEvent;
use App\Modules\Events\Actions\UpdateEvent;
use App\Modules\Events\Actions\WithdrawSubmission;
use App\Modules\Events\Enums\CancelledBy;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Http\Requests\CancelEventRequest;
use App\Modules\Events\Http\Requests\ListOwnEventsRequest;
use App\Modules\Events\Http\Requests\StoreEventRequest;
use App\Modules\Events\Http\Requests\UpdateEventRequest;
use App\Modules\Events\Http\Resources\OwnEventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Support\Organizers;
use App\Support\Pagination;
use App\Support\TwoFactorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Événements de l'organisateur connecté (/me/events). L'événement d'un autre utilisateur donne
 * 404 (EventPolicy), admin compris.
 */
class OwnEventController extends Controller
{
    private const RELATIONS = ['categories', 'cover', 'pendingRevision.cover', 'latestRevision'];

    public function index(ListOwnEventsRequest $request): AnonymousResourceCollection
    {
        $events = Event::query()
            ->where('user_id', self::user($request)->id)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->with(self::RELATIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return OwnEventResource::collection($events);
    }

    public function store(StoreEventRequest $request, CreateEvent $createEvent): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $event = $createEvent->handle(self::user($request), $data);

        return (new OwnEventResource($event->load(self::RELATIONS)))->response()->setStatusCode(201);
    }

    public function show(string $uuid): OwnEventResource
    {
        $event = self::find($uuid);
        Gate::authorize('view', $event);

        return new OwnEventResource($event->load(self::RELATIONS));
    }

    public function update(UpdateEventRequest $request, UpdateEvent $updateEvent): OwnEventResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $event = $updateEvent->handle(self::user($request), $request->event(), $data);

        return new OwnEventResource($event->load(self::RELATIONS));
    }

    public function destroy(string $uuid, DeleteEvent $deleteEvent): Response
    {
        $event = self::find($uuid);
        Gate::authorize('delete', $event);

        $deleteEvent->handle($event);

        return response()->noContent();
    }

    /**
     * Soumission à la validation (202), ou publication directe pour un admin dont la session a
     * été ouverte avec un code 2FA (200). Un admin sans 2FA reçoit 403 : il ne publie jamais
     * sans cette protection.
     */
    public function submit(Request $request, string $uuid, SubmitEvent $submitEvent): JsonResponse
    {
        $user = self::user($request);
        $event = self::find($uuid);
        Gate::authorize('submit', $event);

        $publishDirectly = Organizers::isAdmin($user);

        if ($publishDirectly && ! TwoFactorSession::passed($request)) {
            abort(403, 'La publication par un admin exige la double authentification : activez-la, puis reconnectez-vous avec votre code.');
        }

        $event = $submitEvent->handle($user, $event, $publishDirectly);

        return (new OwnEventResource($event->load(self::RELATIONS)))
            ->response()
            ->setStatusCode($event->status === EventStatus::Published ? 200 : 202);
    }

    public function withdraw(string $uuid, WithdrawSubmission $withdrawSubmission): OwnEventResource
    {
        $event = self::find($uuid);
        Gate::authorize('update', $event);

        return new OwnEventResource($withdrawSubmission->handle($event)->load(self::RELATIONS));
    }

    public function cancel(CancelEventRequest $request, string $uuid, CancelEvent $cancelEvent): OwnEventResource
    {
        $event = self::find($uuid);
        Gate::authorize('cancel', $event);

        $reason = $request->validated('reason');

        $event = $cancelEvent->handle($event, CancelledBy::Organizer, is_string($reason) ? $reason : null);

        return new OwnEventResource($event->load(self::RELATIONS));
    }

    /**
     * abort() plutôt que firstOrFail() : le message 404 ne révèle pas le nom du modèle.
     */
    public static function find(string $uuid): Event
    {
        $event = Event::query()->where('uuid', $uuid)->first();
        abort_if($event === null, 404, 'Événement introuvable.');

        return $event;
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
