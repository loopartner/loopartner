<?php

namespace Database\Factories\Modules\Moderation;

use App\Modules\Moderation\Enums\DecisionGround;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Enums\DecisionSource;
use App\Modules\Moderation\Models\Decision;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Restriction d'un article décidée aujourd'hui sur signalement, contraire aux CGU.
 *
 * @extends Factory<Decision>
 */
class DecisionFactory extends Factory
{
    protected $model = Decision::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'target_type' => 'article',
            'target_ref' => 'un-article-'.Str::lower(Str::random(8)),
            'owner_id' => null,
            'target_label' => 'Titre du contenu',
            'measure' => DecisionMeasure::RestrictVisibility,
            'ground' => DecisionGround::Terms,
            'category' => 'terms',
            'terms_clause' => 'prohibited_content',
            'facts' => 'Le contenu renvoie vers un site de paris en ligne sans rapport avec le sujet annoncé.',
            'source' => DecisionSource::Notice,
            'automated' => false,
            'reversible' => true,
            'decided_at' => now(),
            'appeal_deadline_at' => now()->addMonths(6),
        ];
    }
}
