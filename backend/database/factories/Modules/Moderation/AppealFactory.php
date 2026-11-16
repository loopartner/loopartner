<?php

namespace Database\Factories\Modules\Moderation;

use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\AppellantKind;
use App\Modules\Moderation\Models\Appeal;
use App\Modules\Moderation\Models\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Recours en attente de l'auteur du contenu.
 *
 * @extends Factory<Appeal>
 */
class AppealFactory extends Factory
{
    protected $model = Appeal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'decision_id' => Decision::factory(),
            'appellant_kind' => AppellantKind::Owner,
            'appellant_key' => hash('sha256', 'owner:'.Str::random(8)),
            'statement' => 'Le lien a été retiré, le contenu respecte maintenant les règles de publication.',
            'status' => AppealStatus::Pending,
        ];
    }
}
