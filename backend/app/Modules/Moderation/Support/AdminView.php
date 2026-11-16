<?php

namespace App\Modules\Moderation\Support;

use App\Models\User;
use App\Modules\Moderation\Enums\NoticeStatus;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use App\Modules\Moderation\Models\Notice;
use App\Support\Moderation\ModerationTargets;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Carbon;

/**
 * Ce que voit un admin (2FA de session) dans la modération des signalements.
 *
 * - Le contenu actuel (adaptateur du module), sa copie au moment de chaque signalement et son
 *   empreinte de version (à renvoyer avec la décision).
 * - Aucune image pour un contenu signalé comme abus sexuels sur mineurs : la copie conservée
 *   est textuelle et l'administration ne réaffiche pas les images (images_withheld).
 * - Le signalant : « compte » ou « sans compte », et le nom donné sans compte ; jamais son email
 *   ni l'identifiant de son compte (minimisation).
 * - L'auteur du contenu : rôle, ancienneté, email vérifié, signalements et décisions passés ;
 *   jamais son nom ni son email.
 */
final class AdminView
{
    private const NOTICES_LIMIT = 100;

    /**
     * @return array<string, mixed>
     */
    public static function target(string $type, string $ref, string $locale): array
    {
        $content = ModerationTargets::get($type)->inspect($ref);
        $notices = Notice::query()
            ->where('target_type', $type)
            ->where('target_ref', $content !== null ? $content->ref : $ref)
            ->with('decision')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [NoticeStatus::Open->value])
            ->latest('id')
            ->limit(self::NOTICES_LIMIT)
            ->get();
        $withholdImages = $notices->contains(fn (Notice $notice): bool => Categories::withholdsImages($notice->category));
        $ownerId = $content !== null ? $content->ownerId : $notices->first()?->target_owner_id;

        return [
            'target' => ['type' => $type, 'type_label' => TargetTypes::label($type, $locale), 'ref' => $content !== null ? $content->ref : $ref],
            'content' => $content === null ? null : self::content($content, $withholdImages),
            'owner' => self::owner($ownerId),
            'notices' => $notices->map(fn (Notice $notice): array => self::notice($notice, $content, $locale))->all(),
            'decisions' => Decision::query()
                ->where('target_type', $type)
                ->where('target_ref', $content !== null ? $content->ref : $ref)
                ->latest('id')
                ->get()
                ->map(fn (Decision $decision): array => self::decisionSummary($decision, $locale))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function decision(Decision $decision, string $locale): array
    {
        return [
            ...DecisionView::forOwner($decision, $locale),
            'reversible' => $decision->reversible,
            'notices_count' => $decision->notices()->count(),
            'appeals' => $decision->appeals->map(fn (Appeal $appeal): array => self::appeal($appeal))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function appeal(Appeal $appeal): array
    {
        return [
            'uuid' => $appeal->uuid,
            'decision' => $appeal->decision->uuid,
            'appellant_kind' => $appeal->appellant_kind->value,
            'statement' => $appeal->statement,
            'status' => $appeal->status->value,
            'created_at' => $appeal->created_at?->toIso8601ZuluString(),
            'decided_at' => $appeal->decided_at?->toIso8601ZuluString(),
            'outcome_reason' => $appeal->outcome_reason,
        ];
    }

    /**
     * Ligne de la file : un contenu et ses signalements en cours.
     *
     * @param  object{target_type: string, target_ref: string, notices_count: int|string, urgent: int|string, due_at: string, first_at: string}  $case
     * @return array<string, mixed>
     */
    public static function queueItem(object $case, string $locale): array
    {
        $dueAt = Carbon::parse($case->due_at, 'UTC');

        return [
            'target' => ['type' => $case->target_type, 'type_label' => TargetTypes::label($case->target_type, $locale), 'ref' => $case->target_ref],
            'notices_count' => (int) $case->notices_count,
            'priority' => (int) $case->urgent === 1 ? 'urgent' : 'standard',
            'first_notice_at' => Carbon::parse($case->first_at, 'UTC')->toIso8601ZuluString(),
            'due_at' => $dueAt->toIso8601ZuluString(),
            'overdue' => $dueAt->isPast(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(TargetContent $content, bool $withholdImages): array
    {
        return [
            'label' => $content->label,
            'text' => $content->text,
            'version' => $content->version,
            'restricted' => $content->isRestricted(),
            'restricted_by' => $content->restrictedBy,
            'public_path' => $content->publicPath,
            'images' => $withholdImages ? [] : $content->images,
            'images_withheld' => $withholdImages && $content->images !== [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function owner(?int $ownerId): ?array
    {
        $owner = $ownerId !== null ? User::query()->find($ownerId) : null;

        if ($owner === null) {
            return null;
        }

        return [
            'roles' => $owner->getRoleNames()->values()->all(),
            'account_age_days' => (int) $owner->created_at?->diffInDays(now()),
            'email_verified' => $owner->hasVerifiedEmail(),
            'notices_received' => Notice::query()->where('target_owner_id', $owner->id)->count(),
            'restrictions' => Decision::query()->where('owner_id', $owner->id)->where('measure', 'restrict_visibility')->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function notice(Notice $notice, ?TargetContent $content, string $locale): array
    {
        return [
            'uuid' => $notice->uuid,
            'category' => ['key' => $notice->category, 'label' => Categories::label($notice->category, $locale)],
            'priority' => $notice->priority->value,
            'source' => $notice->source->value,
            'source_reason' => $notice->source_reason,
            'status' => $notice->status->value,
            'explanation' => $notice->explanation,
            'location_detail' => $notice->location_detail,
            'content_label' => $notice->content_label,
            'content_snapshot' => $notice->content_snapshot,
            'content_changed_since' => $content !== null && $notice->content_version !== null && $notice->content_version !== $content->version,
            'reporter' => [
                'kind' => $notice->reporter_id !== null ? 'account' : 'guest',
                'name' => $notice->reporter_id === null ? $notice->reporter_name : null,
            ],
            'good_faith_confirmed' => $notice->good_faith_confirmed_at !== null,
            'manifestly_unfounded' => $notice->manifestly_unfounded,
            'created_at' => $notice->created_at?->toIso8601ZuluString(),
            'due_at' => $notice->due_at->toIso8601ZuluString(),
            'overdue' => $notice->isOverdue(),
            'referred_at' => $notice->referred_at?->toIso8601ZuluString(),
            'decision' => $notice->decision?->uuid,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decisionSummary(Decision $decision, string $locale): array
    {
        return [
            'uuid' => $decision->uuid,
            'measure' => $decision->measure->value,
            'ground' => $decision->ground?->value,
            'category' => $decision->category === null ? null : ['key' => $decision->category, 'label' => Categories::label($decision->category, $locale)],
            'source' => $decision->source->value,
            'reversible' => $decision->reversible,
            'decided_at' => $decision->decided_at->toIso8601ZuluString(),
            'lifted_at' => $decision->lifted_at?->toIso8601ZuluString(),
        ];
    }
}
