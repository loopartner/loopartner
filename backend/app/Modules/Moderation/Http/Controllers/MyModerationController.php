<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Moderation\Http\Requests\ListRequest;
use App\Modules\Moderation\Http\Resources\NoticeResource;
use App\Modules\Moderation\Models\Notice;
use App\Support\Pagination;
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

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
