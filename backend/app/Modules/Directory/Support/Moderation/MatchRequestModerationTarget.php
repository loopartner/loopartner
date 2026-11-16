<?php

namespace App\Modules\Directory\Support\Moderation;

use App\Models\User;
use App\Modules\Directory\Models\MatchRequest;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;

/**
 * Demande de mise en relation (contenu privé), désignée par son uuid. Signalée uniquement par
 * son destinataire (POST /me/match-requests/{uuid}/report), jamais par le formulaire public.
 * Masquée, elle reste dans la liste des demandes sans son texte.
 */
class MatchRequestModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return false;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        if ($viewer === null) {
            return null;
        }

        $request = MatchRequest::query()
            ->where('uuid', $ref)
            ->where('recipient_id', $viewer->id)
            ->whereNull('moderation_restricted_at')
            ->first();

        return $request === null ? null : self::content($request);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $request = MatchRequest::query()->where('uuid', $ref)->first();

        return $request === null ? null : self::content($request);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $request = MatchRequest::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($request !== null) {
                Restriction::apply($request, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $request = MatchRequest::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($request !== null) {
                Restriction::lift($request, $decisionUuid);
            }
        });
    }

    private static function content(MatchRequest $request): TargetContent
    {
        return new TargetContent(
            type: 'match_request',
            ref: $request->uuid,
            ownerId: $request->author_id,
            label: 'Demande de mise en relation',
            text: $request->message,
            version: TargetContent::fingerprint(['message' => $request->message, 'price' => [$request->price_amount, $request->price_currency]]),
            restrictedBy: Restriction::decisionOf($request),
        );
    }
}
