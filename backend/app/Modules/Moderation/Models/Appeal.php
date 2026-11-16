<?php

namespace App\Modules\Moderation\Models;

use App\Modules\Moderation\Enums\AppealStatus;
use App\Modules\Moderation\Enums\AppellantKind;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Moderation\AppealFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recours interne contre une décision (aligné sur DSA art. 20) : gratuit, par voie
 * électronique, examiné par un admin (jamais de manière automatisée). Le texte du recours
 * est chiffré en base. Aucun attribut n'est assignable en masse.
 *
 * appellant_key identifie l'auteur du recours (compte ou signalement) pour limiter le nombre
 * de recours par personne et par décision.
 *
 * @property int $id
 * @property string $uuid
 * @property int $decision_id
 * @property int|null $notice_id
 * @property AppellantKind $appellant_kind
 * @property int|null $appellant_id
 * @property string $appellant_key
 * @property string $statement
 * @property AppealStatus $status
 * @property int|null $decided_by
 * @property Carbon|CarbonImmutable|null $decided_at
 * @property string|null $outcome_reason
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Decision $decision
 * @property-read Notice|null $notice
 */
#[UseFactory(AppealFactory::class)]
class Appeal extends Model
{
    /** @use HasFactory<AppealFactory> */
    use HasFactory;

    protected $table = 'moderation_appeals';

    /**
     * @var list<string>
     */
    protected $hidden = ['appellant_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'appellant_kind' => AppellantKind::class,
            'status' => AppealStatus::class,
            'statement' => 'encrypted',
            'outcome_reason' => 'encrypted',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Decision, $this>
     */
    public function decision(): BelongsTo
    {
        return $this->belongsTo(Decision::class);
    }

    /**
     * @return BelongsTo<Notice, $this>
     */
    public function notice(): BelongsTo
    {
        return $this->belongsTo(Notice::class);
    }

    public function isPending(): bool
    {
        return $this->status === AppealStatus::Pending;
    }
}
