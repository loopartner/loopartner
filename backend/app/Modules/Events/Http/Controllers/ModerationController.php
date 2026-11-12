<?php

namespace App\Modules\Events\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Events\Actions\ApproveEvent;
use App\Modules\Events\Actions\ApproveRevision;
use App\Modules\Events\Actions\CancelEventByModeration;
use App\Modules\Events\Actions\ModerateAnnouncement;
use App\Modules\Events\Actions\RejectEvent;
use App\Modules\Events\Actions\RejectRevision;
use App\Modules\Events\Http\Requests\ModerationDecisionRequest;
use App\Modules\Events\Http\Requests\ModerationQueueRequest;
use App\Modules\Events\Http\Resources\ModerationView;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventAnnouncement;
use App\Modules\Events\Models\EventRevision;
use App\Modules\Events\Support\ModerationQueue;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Modération des événements et des révisions (/admin/…). Accès : rôle admin et double
 * authentification (middleware EnsureModerator) ; 404 pour tout autre rôle.
 */
class ModerationController extends Controller
{
    private const EVENT_RELATIONS = ['user.roles', 'categories', 'cover', 'pendingRevision'];

    /**
     * File d'attente, du plus ancien au plus récent.
     */
    public function queue(ModerationQueueRequest $request): JsonResponse
    {
        $kind = $request->validated('kind');
        $page = ModerationQueue::query(is_string($kind) ? $kind : null)
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $events = Event::query()
            ->whereKey(collect($page->items())->pluck('event_id')->unique()->all())
            ->get()
            ->keyBy('id');

        $items = collect($page->items())->map(function (object $item) use ($events): array {
            /** @var object{kind: string, uuid: string, submitted_at: string, event_id: int} $item */
            $event = $events->get($item->event_id);

            return [
                'kind' => $item->kind,
                'uuid' => $item->uuid,
                'submitted_at' => Carbon::parse($item->submitted_at, 'UTC')->toIso8601ZuluString(),
                'event' => $event instanceof Event ? [
                    'uuid' => $event->uuid,
                    'title' => $event->title,
                    'status' => $event->status->value,
                    'starts_at' => $event->starts_at->toIso8601ZuluString(),
                ] : null,
            ];
        });

        return response()->json([
            'data' => $items->all(),
            'links' => [
                'first' => $page->url(1),
                'last' => $page->url($page->lastPage()),
                'prev' => $page->previousPageUrl(),
                'next' => $page->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => ModerationQueue::counts(),
            ],
        ]);
    }

    public function showEvent(string $uuid): JsonResponse
    {
        return response()->json(['data' => ModerationView::event(self::event($uuid)->load(self::EVENT_RELATIONS))]);
    }

    public function approveEvent(ModerationDecisionRequest $request, string $uuid, ApproveEvent $approveEvent): JsonResponse
    {
        $event = $approveEvent->handle(self::admin($request), self::event($uuid), $request->integer('version'));

        return $this->showEvent($event->uuid);
    }

    public function rejectEvent(ModerationDecisionRequest $request, string $uuid, RejectEvent $rejectEvent): JsonResponse
    {
        $event = $rejectEvent->handle(self::admin($request), self::event($uuid), $request->integer('version'), $request->string('reason')->toString());

        return $this->showEvent($event->uuid);
    }

    public function cancelEvent(ModerationDecisionRequest $request, string $uuid, CancelEventByModeration $cancelEvent): JsonResponse
    {
        $event = $cancelEvent->handle(self::admin($request), self::event($uuid), $request->string('reason')->toString());

        return $this->showEvent($event->uuid);
    }

    public function showRevision(string $uuid): JsonResponse
    {
        return response()->json(['data' => ModerationView::revision(self::revision($uuid))]);
    }

    public function approveRevision(ModerationDecisionRequest $request, string $uuid, ApproveRevision $approveRevision): JsonResponse
    {
        $revision = $approveRevision->handle(self::admin($request), self::revision($uuid), $request->integer('version'));

        return $this->showRevision($revision->uuid);
    }

    public function rejectRevision(ModerationDecisionRequest $request, string $uuid, RejectRevision $rejectRevision): JsonResponse
    {
        $revision = $rejectRevision->handle(self::admin($request), self::revision($uuid), $request->integer('version'), $request->string('reason')->toString());

        return $this->showRevision($revision->uuid);
    }

    public function showAnnouncement(string $uuid): JsonResponse
    {
        return response()->json(['data' => ModerationView::announcement(self::announcement($uuid))]);
    }

    public function approveAnnouncement(ModerationDecisionRequest $request, string $uuid, ModerateAnnouncement $moderateAnnouncement): JsonResponse
    {
        $announcement = $moderateAnnouncement->approve(self::admin($request), self::announcement($uuid));

        return $this->showAnnouncement($announcement->uuid);
    }

    public function rejectAnnouncement(ModerationDecisionRequest $request, string $uuid, ModerateAnnouncement $moderateAnnouncement): JsonResponse
    {
        $announcement = $moderateAnnouncement->reject(self::admin($request), self::announcement($uuid), $request->string('reason')->toString());

        return $this->showAnnouncement($announcement->uuid);
    }

    private static function announcement(string $uuid): EventAnnouncement
    {
        $announcement = EventAnnouncement::query()->where('uuid', $uuid)->with('event.user.roles')->first();
        abort_if($announcement === null, 404, 'Annonce introuvable.');

        return $announcement;
    }

    /**
     * Brouillons exclus : un admin ne lit pas un événement que son organisateur n'a jamais soumis.
     */
    private static function event(string $uuid): Event
    {
        $event = Event::query()->where('uuid', $uuid)->where('status', '!=', 'draft')->first();
        abort_if($event === null, 404, 'Événement introuvable.');

        return $event;
    }

    private static function revision(string $uuid): EventRevision
    {
        $revision = EventRevision::query()
            ->where('uuid', $uuid)
            ->with(['event.user.roles', 'event.categories', 'event.cover', 'cover'])
            ->first();
        abort_if($revision === null, 404, 'Modification introuvable.');

        return $revision;
    }

    private static function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
