<?php

namespace App\Modules\Directory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Directory\Actions\MarkConversationRead;
use App\Modules\Directory\Actions\ReportContent;
use App\Modules\Directory\Actions\SendMessage;
use App\Modules\Directory\Http\Requests\ListMessagesRequest;
use App\Modules\Directory\Http\Requests\MarkConversationReadRequest;
use App\Modules\Directory\Http\Requests\ReportMessageRequest;
use App\Modules\Directory\Http\Requests\StoreMessageRequest;
use App\Modules\Directory\Http\Resources\AbuseReportResource;
use App\Modules\Directory\Http\Resources\MessageResource;
use App\Modules\Directory\Models\ConversationParticipant;
use App\Modules\Directory\Models\Message;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Messages d'une conversation : liste (paginée, ou seulement les nouveaux avec "after"), envoi,
 * marquage comme lu et signalement. L'appartenance à la conversation est vérifiée par les
 * FormRequest (ConversationRequest) avant toute validation : 404 sinon.
 */
class MessageController extends Controller
{
    /**
     * Nombre maximal de messages renvoyés avec "after" ; meta.has_more indique s'il en reste.
     */
    public const MAX_NEW_MESSAGES = 100;

    public function index(ListMessagesRequest $request): AnonymousResourceCollection
    {
        $conversation = $request->conversation();

        if ($request->filled('after')) {
            $afterId = Message::query()
                ->where('conversation_id', $conversation->id)
                ->where('uuid', $request->string('after')->toString())
                ->value('id');

            $messages = $conversation->messages()
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit(self::MAX_NEW_MESSAGES + 1)
                ->get();

            return MessageResource::collection($messages->take(self::MAX_NEW_MESSAGES))
                ->additional(['meta' => ['has_more' => $messages->count() > self::MAX_NEW_MESSAGES]]);
        }

        $messages = $conversation->messages()
            ->orderByDesc('id')
            ->paginate(Pagination::perPage($request))
            ->withQueryString();

        return MessageResource::collection($messages);
    }

    public function store(StoreMessageRequest $request, SendMessage $sendMessage): JsonResponse
    {
        $message = $sendMessage->handle($this->user($request), $request->conversation(), $request->string('body')->toString());

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * POST /me/conversations/{uuid}/read
     */
    public function read(MarkConversationReadRequest $request, MarkConversationRead $markRead): Response
    {
        $conversation = $request->conversation();

        /** @var ConversationParticipant $participant */
        $participant = $conversation->participantFor($this->user($request));

        $upTo = $request->filled('up_to')
            ? Message::query()->where('conversation_id', $conversation->id)->where('uuid', $request->string('up_to')->toString())->first()
            : null;

        $markRead->handle($participant, $upTo);

        return response()->noContent();
    }

    /**
     * POST /me/conversations/{uuid}/messages/{messageUuid}/report
     */
    public function report(ReportMessageRequest $request, ReportContent $reportContent): JsonResponse
    {
        /** @var array{reason: string, comment?: string|null} $data */
        $data = $request->validated();

        return (new AbuseReportResource($reportContent->handle($this->user($request), $request->reportedMessage(), $data)))
            ->response()
            ->setStatusCode(201);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
