<?php

namespace App\Modules\Moderation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Moderation\Actions\SubmitNotice;
use App\Modules\Moderation\Http\Requests\StoreNoticeRequest;
use App\Modules\Moderation\Http\Requests\TokenRequest;
use App\Modules\Moderation\Http\Resources\NoticeResource;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\Categories;
use App\Modules\Moderation\Support\TargetTypes;
use App\Modules\Moderation\Support\TermsClauses;
use App\Modules\Moderation\Support\TrackingToken;
use App\Support\Locale;
use App\Support\Moderation\ModerationTargets;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Routes publiques de la modération : informations (points de contact, catégories, règles de
 * recours), signalement d'un contenu public, suivi d'un signalement fait sans compte.
 */
class PublicModerationController extends Controller
{
    /**
     * GET /moderation/info : ce que le frontend affiche avant un signalement et dans les pages
     * d'information (DSA art. 11, 12 et 14).
     */
    public function info(Request $request): JsonResponse
    {
        $locale = Locale::fromRequest($request);

        return response()->json(['data' => [
            'contact' => [
                'users_email' => config('moderation.contact.users_email'),
                'authorities_email' => config('moderation.contact.authorities_email'),
                'languages' => config('moderation.contact.languages'),
            ],
            'target_types' => array_map(fn (string $type): array => [
                'key' => $type,
                'label' => TargetTypes::label($type, $locale),
            ], ModerationTargets::publicTypes()),
            'categories' => Categories::describe($locale),
            'terms_clauses' => TermsClauses::all($locale),
            'appeal_window_months' => (int) config('moderation.appeal_window_months'),
            'automated_decisions' => false,
            'explanation' => config('moderation.limits.explanation'),
        ]])->header('Vary', 'Accept-Language');
    }

    /**
     * POST /moderation/notices (201). Sans compte, la réponse donne une seule fois le jeton de
     * suivi (aussi envoyé par email avec l'accusé de réception).
     */
    public function store(StoreNoticeRequest $request, SubmitNotice $submitNotice): JsonResponse
    {
        $reporter = $request->user();
        /** @var array{target_type: string, target_ref: string, category: string, explanation: string, location_detail?: string|null, name?: string|null, email?: string|null} $data */
        $data = $request->validated();

        [$notice, $token] = $submitNotice->public($reporter instanceof User ? $reporter : null, $data, Locale::fromRequest($request));

        return (new NoticeResource($notice->load('decision.appeals')))
            ->additional($token === null ? [] : ['tracking_token' => $token])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * POST /moderation/notices/{uuid}/lookup : suivi d'un signalement fait sans compte, avec
     * son jeton. Jeton inconnu ou invalide : 404 (sans dire si le signalement existe).
     */
    public function lookup(TokenRequest $request, string $uuid): NoticeResource
    {
        return new NoticeResource(self::byToken($uuid, $request->string('token')->toString())->load('decision.appeals'));
    }

    public static function byToken(string $uuid, #[\SensitiveParameter] string $token): Notice
    {
        $notice = Notice::query()->where('uuid', $uuid)->whereNull('reporter_id')->first();
        abort_if($notice === null || ! TrackingToken::matches($notice->tracking_token_hash, $token), 404, 'Signalement introuvable.');

        return $notice;
    }
}
