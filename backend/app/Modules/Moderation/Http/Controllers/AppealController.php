<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Moderation\Actions\DecideAppeal;
use App\Modules\Moderation\Actions\SubmitAppeal;
use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Http\Requests\AppealRequest;
use App\Modules\Moderation\Http\Requests\DecideAppealRequest;
use App\Modules\Moderation\Http\Requests\ListRequest;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\AdminView;
use App\Modules\Moderation\Support\DecisionView;
use App\Support\Locale;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recours interne : dépôt par l'auteur du contenu ou par le signalant (avec son compte ou son
 * jeton), examen par un admin. La décision ou le signalement d'un autre : 404.
 */
class AppealController extends Controller
{
    /**
     * POST /me/moderation/decisions/{uuid}/appeal (201) : l'auteur du contenu.
     */
    public function byOwner(AppealRequest $request, string $uuid, SubmitAppeal $submitAppeal): JsonResponse
    {
        $user = self::user($request);
        $decision = Decision::query()->where('uuid', $uuid)->first();
        abort_if($decision === null, 404, 'Décision introuvable.');
        Gate::forUser($user)->authorize('view', $decision);

        return self::created($submitAppeal->byOwner($user, $decision, $request->string('statement')->toString()));
    }

    /**
     * POST /me/moderation/notices/{uuid}/appeal (201) : le signalant connecté.
     */
    public function byMember(AppealRequest $request, string $uuid, SubmitAppeal $submitAppeal): JsonResponse
    {
        $user = self::user($request);
        $notice = Notice::query()->where('uuid', $uuid)->with('decision')->first();
        abort_if($notice === null, 404, 'Signalement introuvable.');
        Gate::forUser($user)->authorize('view', $notice);

        return self::created($submitAppeal->byNotifier($notice, $user, $request->string('statement')->toString()));
    }

    /**
     * POST /moderation/notices/{uuid}/appeal (201) : le signalant sans compte, avec son jeton
     * (404 sinon).
     */
    public function byGuest(AppealRequest $request, string $uuid, SubmitAppeal $submitAppeal): JsonResponse
    {
        $notice = PublicModerationController::byToken($uuid, $request->string('token')->toString())->load('decision');

        return self::created($submitAppeal->byNotifier($notice, null, $request->string('statement')->toString()));
    }

    /**
     * GET /admin/moderation/appeals : recours en attente, du plus ancien au plus récent.
     */
    public function queue(ListRequest $request): JsonResponse
    {
        $appeals = Appeal::query()
            ->where('status', AppealStatus::Pending->value)
            ->with('decision')
            ->oldest('id')
            ->paginate(Pagination::perPage($request));

        return response()->json([
            'data' => array_map(fn (Appeal $appeal): array => AdminView::appeal($appeal), $appeals->items()),
            'links' => ['next' => $appeals->nextPageUrl(), 'prev' => $appeals->previousPageUrl()],
            'meta' => ['current_page' => $appeals->currentPage(), 'last_page' => $appeals->lastPage(), 'per_page' => $appeals->perPage(), 'total' => $appeals->total()],
        ]);
    }

    /**
     * GET /admin/moderation/appeals/{uuid} : le recours et la décision contestée.
     */
    public function show(Request $request, string $uuid): JsonResponse
    {
        $appeal = self::find($uuid);

        return response()->json(['data' => [
            ...AdminView::appeal($appeal),
            'contested_decision' => AdminView::decision($appeal->decision->load('appeals.decision'), Locale::fromRequest($request)),
        ]]);
    }

    /**
     * POST /admin/moderation/appeals/{uuid}/decide
     */
    public function decide(DecideAppealRequest $request, string $uuid, DecideAppeal $decideAppeal): JsonResponse
    {
        $appeal = $decideAppeal->handle(self::user($request), self::find($uuid), $request->boolean('upheld'), $request->string('reason')->toString(), $request);

        return response()->json(['data' => AdminView::appeal($appeal)]);
    }

    private static function find(string $uuid): Appeal
    {
        $appeal = Appeal::query()->where('uuid', $uuid)->with('decision')->first();
        abort_if($appeal === null, 404, 'Recours introuvable.');

        return $appeal;
    }

    private static function created(Appeal $appeal): JsonResponse
    {
        return response()->json(['data' => DecisionView::appeals([$appeal])[0]], 201);
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
