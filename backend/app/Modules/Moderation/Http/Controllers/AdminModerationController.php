<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Moderation\Actions\DecideOnContent;
use App\Modules\Moderation\Actions\LiftDecision;
use App\Modules\Moderation\Actions\ReferNotice;
use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\NoticePriority;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Http\Requests\DecideRequest;
use App\Modules\Moderation\Http\Requests\ListRequest;
use App\Modules\Moderation\Http\Requests\ReasonRequest;
use App\Modules\Moderation\Http\Requests\ReferralRequest;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\AdminView;
use App\Support\AdminAudit;
use App\Support\Locale;
use App\Support\Moderation\ModerationTargets;
use App\Support\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Modération des signalements (/admin/moderation, admins avec 2FA de session : EnsureModerator).
 * Un type de contenu inconnu, un contenu sans signalement ni existence, une décision ou un
 * signalement inconnus : 404.
 */
class AdminModerationController extends Controller
{
    /**
     * GET /admin/moderation/queue : contenus signalés en cours d'examen, les urgents d'abord,
     * puis par objectif de traitement le plus proche ; meta.counts (en retard compris).
     */
    public function queue(ListRequest $request): JsonResponse
    {
        $locale = Locale::fromRequest($request);
        $cases = Notice::query()->toBase()
            ->selectRaw('target_type, target_ref, COUNT(*) as notices_count, MAX(CASE WHEN priority = ? THEN 1 ELSE 0 END) as urgent, MIN(due_at) as due_at, MIN(created_at) as first_at', [NoticePriority::Urgent->value])
            ->where('status', NoticeStatus::Open->value)
            ->groupBy('target_type', 'target_ref');

        $page = DB::query()->fromSub($cases, 'cases')
            ->orderByDesc('urgent')
            ->orderBy('due_at')
            ->orderBy('target_ref')
            ->orderBy('target_type')
            ->paginate(Pagination::perPage($request));

        $all = DB::query()->fromSub($cases, 'cases');

        return response()->json([
            'data' => array_map(fn (object $case): array => AdminView::queueItem($case, $locale), $page->items()),
            'links' => ['next' => $page->nextPageUrl(), 'prev' => $page->previousPageUrl()],
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'counts' => [
                    'open_notices' => Notice::query()->where('status', NoticeStatus::Open->value)->count(),
                    'urgent_targets' => (clone $all)->where('urgent', 1)->count(),
                    'overdue_targets' => (clone $all)->where('due_at', '<', now())->count(),
                    'pending_appeals' => Appeal::query()->where('status', AppealStatus::Pending->value)->count(),
                ],
            ],
        ]);
    }

    /**
     * GET /admin/moderation/targets/{type}/{ref} : dossier d'un contenu. La consultation d'un
     * contenu signalé est inscrite au journal d'audit.
     */
    public function target(Request $request, string $type, string $ref): JsonResponse
    {
        abort_unless(ModerationTargets::has($type), 404, 'Contenu introuvable.');
        $view = AdminView::target($type, $ref, Locale::fromRequest($request));
        abort_if($view['content'] === null && $view['notices'] === [], 404, 'Contenu introuvable.');

        if ($view['notices'] !== []) {
            AdminAudit::record(self::admin($request), 'moderation.target.viewed', 'moderation_notice', $view['notices'][0]['uuid'], request: $request);
        }

        return response()->json(['data' => $view]);
    }

    /**
     * POST /admin/moderation/targets/{type}/{ref}/decisions (201).
     */
    public function decide(DecideRequest $request, string $type, string $ref, DecideOnContent $decideOnContent): JsonResponse
    {
        abort_unless(ModerationTargets::has($type), 404, 'Contenu introuvable.');
        /** @var array{measure: string, ground?: string|null, category?: string|null, legal_reference?: string|null, terms_clause?: string|null, facts: string, correction_hint?: string|null, version?: string|null, manifestly_unfounded?: bool|null} $data */
        $data = $request->validated();
        $decision = $decideOnContent->handle(self::admin($request), $type, $ref, $data, $request);

        return response()->json(['data' => AdminView::decision($decision->load('appeals.decision'), Locale::fromRequest($request))], 201);
    }

    /**
     * GET /admin/moderation/decisions/{uuid}
     */
    public function decision(Request $request, string $uuid): JsonResponse
    {
        return response()->json(['data' => AdminView::decision(self::findDecision($uuid), Locale::fromRequest($request))]);
    }

    /**
     * POST /admin/moderation/decisions/{uuid}/lift
     */
    public function lift(ReasonRequest $request, string $uuid, LiftDecision $liftDecision): JsonResponse
    {
        $decision = $liftDecision->handle(self::admin($request), self::findDecision($uuid), $request->string('reason')->toString(), $request);

        return response()->json(['data' => AdminView::decision($decision->load('appeals.decision'), Locale::fromRequest($request))]);
    }

    /**
     * POST /admin/moderation/notices/{uuid}/referral : trace d'un signalement aux autorités
     * (DSA art. 18), fait par l'admin lui-même.
     */
    public function referral(ReferralRequest $request, string $uuid, ReferNotice $referNotice): JsonResponse
    {
        $notice = Notice::query()->where('uuid', $uuid)->first();
        abort_if($notice === null, 404, 'Signalement introuvable.');

        $notice = $referNotice->handle(self::admin($request), $notice, $request->string('authority')->toString(), $request);

        return response()->json(['data' => ['uuid' => $notice->uuid, 'referred_at' => $notice->referred_at?->toIso8601ZuluString()]]);
    }

    private static function findDecision(string $uuid): Decision
    {
        $decision = Decision::query()->where('uuid', $uuid)->with('appeals.decision')->first();
        abort_if($decision === null, 404, 'Décision introuvable.');

        return $decision;
    }

    private static function admin(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
