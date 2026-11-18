<?php

namespace App\Modules\Moderation\Models;

use App\Modules\Moderation\Enums\DecisionGround;
use App\Modules\Moderation\Enums\DecisionMeasure;
use App\Modules\Moderation\Enums\DecisionSource;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Moderation\DecisionFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Décision de modération et son exposé des motifs (DSA art. 17 §3) : mesure, fondement
 * (référence juridique ou clause des CGU), faits, origine (signalement, initiative,
 * modération avant publication), absence de moyens automatisés, voies de recours (délai).
 *
 * Aucun attribut n'est assignable en masse. Une décision prise dans Events ou le Blog
 * (source pre_publication_review) n'est pas réversible ici : ses effets restent gérés par le
 * module (correction et nouvelle soumission).
 *
 * @property int $id
 * @property string $uuid
 * @property string $target_type
 * @property string $target_ref
 * @property int|null $owner_id
 * @property string|null $target_label
 * @property DecisionMeasure $measure
 * @property DecisionGround|null $ground
 * @property string|null $category
 * @property string|null $legal_reference
 * @property string|null $terms_clause
 * @property string $facts
 * @property string|null $correction_hint
 * @property DecisionSource $source
 * @property bool $automated
 * @property bool $reversible
 * @property string|null $content_version
 * @property int|null $decided_by
 * @property Carbon|CarbonImmutable $decided_at
 * @property Carbon|CarbonImmutable|null $appeal_deadline_at
 * @property Carbon|CarbonImmutable|null $lifted_at
 * @property int|null $lifted_by
 * @property string|null $lift_reason
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[UseFactory(DecisionFactory::class)]
class Decision extends Model
{
    /** @use HasFactory<DecisionFactory> */
    use HasFactory;

    protected $table = 'moderation_decisions';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'measure' => DecisionMeasure::class,
            'ground' => DecisionGround::class,
            'source' => DecisionSource::class,
            'target_label' => 'encrypted',
            'facts' => 'encrypted',
            'correction_hint' => 'encrypted',
            'lift_reason' => 'encrypted',
            'automated' => 'boolean',
            'reversible' => 'boolean',
            'decided_at' => 'datetime',
            'appeal_deadline_at' => 'datetime',
            'lifted_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Notice, $this>
     */
    public function notices(): HasMany
    {
        return $this->hasMany(Notice::class);
    }

    /**
     * @return HasMany<Appeal, $this>
     */
    public function appeals(): HasMany
    {
        return $this->hasMany(Appeal::class)->orderBy('id');
    }

    /**
     * Restriction encore en vigueur.
     */
    public function isActiveRestriction(): bool
    {
        return $this->measure->restricts() && $this->lifted_at === null;
    }

    public function appealOpen(): bool
    {
        return $this->appeal_deadline_at !== null && $this->appeal_deadline_at->isFuture();
    }
}
