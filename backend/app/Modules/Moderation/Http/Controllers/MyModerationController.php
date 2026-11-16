<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Http\Requests\ListRequest;
use App\Modules\Moderation\Http\Resources\NoticeResource;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\DecisionView;
use App\Support\Locale;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Espace de l'utilisateur connecté : ses signalements (et, plus loin, les décisions qui visent
 * ses contenus et ses recours). Le signalement d'un autre utilisateur donne 404.
 */
class MyModerationController extends Controller
{
    /**
     * GET /me/moderation/notices : mes signalements, du plus récent au plus ancien.
     */
    public function notices(ListRequest $request): AnonymousResourceCollection
    {
        $notices = Notice::query()
            ->where('reporter_id', self::user($request)->id)
            ->with('decision.appeals')
            ->latest('id')
            ->paginate(Pagination::perPage($request));

        return NoticeResource::collection($notices);
    }

    /**
     * GET /me/moderation/notices/{uuid}
     */
    public function notice(Request $request, string $uuid): NoticeResource
    {
        $notice = Notice::query()->where('uuid', $uuid)->with('decision.appeals')->first();
        abort_if($notice === null, 404, 'Signalement introuvable.');
        Gate::forUser(self::user($request))->authorize('view', $notice);

        return new NoticeResource($notice);
    }

    /**
     * GET /me/moderation/decisions : décisions qui visent mes contenus (exposés des motifs), y
     * compris celles de la modération avant publication des événements et des articles.
     */
    public function decisions(ListRequest $request): JsonResponse
    {
        $locale = Locale::fromRequest($request);
        $decisions = Decision::query()
            ->where('owner_id', self::user($request)->id)
            ->where('measure', DecisionMeasure::RestrictVisibility->value)
            ->with('appeals')
            ->latest('id')
            ->paginate(Pagination::perPage($request));

        return response()->json([
            'data' => array_map(fn (Decision $decision): array => DecisionView::forOwner($decision, $locale), $decisions->items()),
            'links' => ['next' => $decisions->nextPageUrl(), 'prev' => $decisions->previousPageUrl()],
            'meta' => ['current_page' => $decisions->currentPage(), 'last_page' => $decisions->lastPage(), 'per_page' => $decisions->perPage(), 'total' => $decisions->total()],
        ]);
    }

    /**
     * GET /me/moderation/decisions/{uuid} : exposé des motifs complet (DSA art. 17 §3).
     */
    public function decision(Request $request, string $uuid): JsonResponse
    {
        $decision = Decision::query()->where('uuid', $uuid)->with('appeals')->first();
        abort_if($decision === null, 404, 'Décision introuvable.');
        Gate::forUser(self::user($request))->authorize('view', $decision);

        return response()->json(['data' => DecisionView::forOwner($decision, Locale::fromRequest($request))]);
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
