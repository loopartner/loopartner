<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Actions\PostAnnouncement;
use App\Modules\Events\Actions\RemoveRegistrant;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Http\Requests\PaginatedRequest;
use App\Modules\Events\Http\Requests\StoreAnnouncementRequest;
use App\Modules\Events\Http\Resources\AnnouncementResource;
use App\Modules\Events\Http\Resources\RegistrantResource;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRegistration;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Inscrits et annonces d'un événement, pour son organisateur uniquement (404 pour tout autre
 * utilisateur, admin compris). L'organisateur ne reçoit jamais les coordonnées des inscrits :
 * il communique avec eux par des annonces envoyées par la plateforme.
 */
class AttendeeController extends Controller
{
    public function registrants(PaginatedRequest $request, string $uuid): AnonymousResourceCollection
    {
        $event = OwnEventController::find($uuid);
        Gate::authorize('manageAttendees', $event);

        $registrations = EventRegistration::query()
            ->where('event_id', $event->id)
            ->where('status', RegistrationStatus::Registered->value)
            ->with('user.roles')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return RegistrantResource::collection($registrations)->additional(['meta' => [
            'registrations_count' => $event->registrations_count,
            'registration_limit' => $event->is_free ? $event->registrationLimit() : null,
        ]]);
    }

    public function removeRegistrant(string $uuid, string $registrationUuid, RemoveRegistrant $removeRegistrant): Response
    {
        $event = OwnEventController::find($uuid);
        Gate::authorize('manageAttendees', $event);

        $removeRegistrant->handle($event, $registrationUuid);

        return response()->noContent();
    }

    public function announcements(PaginatedRequest $request, string $uuid): AnonymousResourceCollection
    {
        $event = OwnEventController::find($uuid);
        Gate::authorize('manageAttendees', $event);

        $announcements = EventAnnouncement::query()
            ->where('event_id', $event->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return AnnouncementResource::collection(
            $announcements->through(fn (EventAnnouncement $announcement): AnnouncementResource => (new AnnouncementResource($announcement))->forOrganizer()),
        );
    }

    /**
     * 201 : publiée tout de suite (sans lien) ou en attente de validation (avec un lien).
     */
    public function postAnnouncement(StoreAnnouncementRequest $request, string $uuid, PostAnnouncement $postAnnouncement): JsonResponse
    {
        $event = OwnEventController::find($uuid);
        Gate::authorize('manageAttendees', $event);

        /** @var User $user */
        $user = $request->user();
        $announcement = $postAnnouncement->handle($user, $event, $request->string('body')->toString());

        return (new AnnouncementResource($announcement))->forOrganizer()->response()->setStatusCode(201);
    }
}
