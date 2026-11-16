<?php

namespace App\Modules\Directory\Support\Moderation;

use App\Models\User;
use App\Modules\Directory\Models\Message;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Message de la messagerie (contenu privé), désigné par son uuid. Signalé uniquement depuis
 * la conversation par son destinataire (POST /me/conversations/{uuid}/messages/{messageUuid}/report),
 * jamais par le formulaire public. Masqué, il garde sa place dans la conversation sans son texte.
 */
class MessageModerationTarget implements ModerationTarget
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

        $message = Message::query()
            ->where('uuid', $ref)
            ->whereNull('moderation_restricted_at')
            ->whereHas('conversation.participants', fn (Builder $participants) => $participants->where('user_id', $viewer->id))
            ->first();

        return $message === null ? null : self::content($message);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $message = Message::query()->where('uuid', $ref)->first();

        return $message === null ? null : self::content($message);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $message = Message::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($message !== null) {
                Restriction::apply($message, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $message = Message::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($message !== null) {
                Restriction::lift($message, $decisionUuid);
            }
        });
    }

    private static function content(Message $message): TargetContent
    {
        return new TargetContent(
            type: 'message',
            ref: $message->uuid,
            ownerId: $message->sender_id,
            label: 'Message',
            text: $message->body,
            version: TargetContent::fingerprint(['body' => $message->body]),
            restrictedBy: Restriction::decisionOf($message),
        );
    }
}
