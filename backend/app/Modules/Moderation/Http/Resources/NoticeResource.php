<?php

namespace App\Modules\Moderation\Http\Resources;

use App\Modules\Moderation\Enums\AppellantKind;
use App\Modules\Moderation\Models\Notice;
use App\Modules\Moderation\Support\Categories;
use App\Modules\Moderation\Support\DecisionView;
use App\Modules\Moderation\Support\TargetTypes;
use App\Support\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Signalement vu par son auteur (avec son compte ou son jeton de suivi) : le contenu visé, la
 * catégorie, l'état et, une fois décidé, la décision (sans les faits) et le recours possible
 * contre une absence de mesure. Ni identifiant interne, ni identité, ni copie du contenu.
 *
 * Relations à charger : decision.appeals.
 *
 * @mixin Notice
 */
class NoticeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = Locale::fromRequest($request);
        $decision = $this->decision;

        return [
            'uuid' => $this->uuid,
            'target' => [
                'type' => $this->target_type,
                'type_label' => TargetTypes::label($this->target_type, $locale),
                'ref' => $this->target_ref,
            ],
            'category' => ['key' => $this->category, 'label' => Categories::label($this->category, $locale)],
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'decision' => $decision === null ? null : [
                ...DecisionView::forNotifier($decision, $locale),
                'appeal' => DecisionView::appealState(
                    $decision,
                    $decision->appeals->where('notice_id', $this->id)->where('appellant_kind', AppellantKind::Notifier),
                    eligible: ! $decision->measure->restricts(),
                ),
                'appeals' => DecisionView::appeals($decision->appeals->where('notice_id', $this->id)->where('appellant_kind', AppellantKind::Notifier)),
            ],
        ];
    }
}
