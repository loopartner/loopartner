<?php

namespace App\Modules\Directory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Directory\Actions\BlockUser;
use App\Modules\Directory\Actions\UnblockUser;
use App\Modules\Directory\Http\Resources\ConversationResource;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\Message;
use App\Modules\Directory\Support\ViewerContext;
use App\Support\Pagination;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Conversations de l'utilisateur connecté : liste avec le nombre de messages non lus, compteur
 * global (pour l'interrogation régulière du frontend), détail et blocage de l'interlocuteur.
 * Un non-participant reçoit 404 (ConversationPolicy).
 */
class ConversationController extends Controller
{
    private const RELATIONS = ['participants.user.roles', 'matchRequest.project', 'lastMessage'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(Pagination::rules());
        $user = $this->user($request);

        $conversations = $this->withUnreadCount(Conversation::query(), $user)
            ->with(self::RELATIONS)
            ->orderByDesc('conversations.last_activity_at')
            ->orderByDesc('conversations.id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        $context = ViewerContext::build($user, $conversations->getCollection()->map(
            fn (Conversation $conversation): ?User => $conversation->otherParticipant($user)?->user,
        ));

        return ConversationResource::collection(
            $conversations->through(fn (Conversation $conversation): ConversationResource => new ConversationResource($conversation, $context)),
        );
    }

    /**
     * GET /me/conversations/unread : nombre de conversations ayant des messages non lus, et
     * nombre total de messages non lus. Une seule requête, pour un badge interrogé régulièrement.
     */
    public function unread(Request $request): JsonResponse
    {
        $user = $this->user($request);

        $counts = Message::query()
            ->join('conversation_participants as viewer', function ($join) use ($user): void {
                $join->on('viewer.conversation_id', '=', 'messages.conversation_id')
                    ->where('viewer.user_id', '=', $user->id);
            })
            ->whereRaw('messages.id > COALESCE(viewer.last_read_message_id, 0)')
            ->where(fn (Builder $query) => $query->whereNull('messages.sender_id')->orWhere('messages.sender_id', '!=', $user->id))
            ->selectRaw('COUNT(*) AS messages, COUNT(DISTINCT messages.conversation_id) AS conversations')
            ->toBase()
            ->first();

        return response()->json(['data' => [
            'conversations' => (int) ($counts->conversations ?? 0),
            'messages' => (int) ($counts->messages ?? 0),
        ]]);
    }

    public function show(Request $request, string $uuid): ConversationResource
    {
        $user = $this->user($request);
        $conversation = $this->withUnreadCount(Conversation::query(), $user)
            ->with(self::RELATIONS)
            ->where('conversations.uuid', $uuid)
            ->first();

        // Inconnue ou d'un autre utilisateur : même 404 (la requête ne renvoie que les
        // conversations de l'utilisateur ; la Policy le vérifie aussi).
        abort_if($conversation === null, 404, 'Conversation introuvable.');
        Gate::authorize('view', $conversation);

        return new ConversationResource($conversation, ViewerContext::build($user, [$conversation->otherParticipant($user)?->user]));
    }

    /**
     * POST /me/conversations/{uuid}/block : bloque l'interlocuteur (idempotent).
     */
    public function block(Request $request, string $uuid, BlockUser $blockUser): Response
    {
        $user = $this->user($request);
        $blockUser->handle($user, $this->otherUserId($user, $this->find($uuid, 'block')));

        return response()->noContent();
    }

    /**
     * DELETE /me/conversations/{uuid}/block : lève le blocage posé par l'utilisateur (idempotent).
     */
    public function unblock(Request $request, string $uuid, UnblockUser $unblockUser): Response
    {
        $user = $this->user($request);
        $unblockUser->handle($user, $this->otherUserId($user, $this->find($uuid, 'block')));

        return response()->noContent();
    }

    /**
     * Conversations de l'utilisateur, avec unread_count : messages de l'autre participant plus
     * récents que son marqueur de lecture.
     *
     * @param  Builder<Conversation>  $query
     * @return Builder<Conversation>
     */
    private function withUnreadCount(Builder $query, User $user): Builder
    {
        return $query
            ->join('conversation_participants as viewer', function ($join) use ($user): void {
                $join->on('viewer.conversation_id', '=', 'conversations.id')
                    ->where('viewer.user_id', '=', $user->id);
            })
            ->select('conversations.*')
            ->withCount(['messages as unread_count' => fn (Builder $messages) => $messages
                ->whereRaw('messages.id > COALESCE(viewer.last_read_message_id, 0)')
                ->where(fn (Builder $query) => $query->whereNull('messages.sender_id')->orWhere('messages.sender_id', '!=', $user->id)),
            ]);
    }

    private function find(string $uuid, string $ability): Conversation
    {
        $conversation = Conversation::query()->with('participants')->where('uuid', $uuid)->first();
        abort_if($conversation === null, 404, 'Conversation introuvable.');
        Gate::authorize($ability, $conversation);

        return $conversation;
    }

    /**
     * @throws ConflictHttpException
     */
    private function otherUserId(User $user, Conversation $conversation): int
    {
        $otherId = $conversation->otherParticipant($user)?->user_id;

        if ($otherId === null) {
            throw new ConflictHttpException('Le compte de votre interlocuteur a été supprimé.');
        }

        return $otherId;
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
