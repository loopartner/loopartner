<?php

namespace App\Modules\Compliance\Models;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Compliance\ComplianceRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\App;

/**
 * Règle fiscale ou sociale d'un pays (statut, échéance, seuil, taux, parcours).
 *
 * - Aucun attribut assignable en masse : le contenu passe par RuleContent (normalisé) et le
 *   cycle de vie (statut, version, vérification, auteurs) par les Actions.
 * - Une règle n'est montrée aux utilisateurs que vérifiée (scope usable()). Une règle de
 *   démonstration (is_demo) n'est utilisable qu'en environnement local.
 * - title et summary : {"fr": "…", "en": "…"|null} ; translated() se replie sur le français.
 *
 * @property int $id
 * @property string $uuid
 * @property string $country
 * @property RuleKind $kind
 * @property string $code
 * @property CarbonInterface $valid_from
 * @property CarbonInterface|null $valid_until
 * @property RuleStatus $status
 * @property int $version
 * @property array{fr: string, en: string|null} $title
 * @property array{fr: string|null, en: string|null}|null $summary
 * @property array<string, mixed> $conditions
 * @property array<string, mixed> $payload
 * @property string|null $source_url
 * @property string|null $source_label
 * @property CarbonInterface|null $last_verified_at
 * @property string|null $verification_note
 * @property string|null $internal_notes
 * @property int|null $replaces_rule_id
 * @property bool $is_demo
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $verified_by
 * @property CarbonInterface|null $retired_at
 * @property string|null $retirement_reason
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[UseFactory(ComplianceRuleFactory::class)]
class ComplianceRule extends Model
{
    /** @use HasFactory<ComplianceRuleFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
        'is_demo' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RuleKind::class,
            'status' => RuleStatus::class,
            'version' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'title' => 'array',
            'summary' => 'array',
            'conditions' => 'array',
            'payload' => 'array',
            'last_verified_at' => 'datetime',
            'is_demo' => 'boolean',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * Règle vérifiée que ce brouillon remplacera.
     *
     * @return BelongsTo<self, $this>
     */
    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_rule_id');
    }

    /**
     * Texte traduit d'un champ JSON {fr, en}, avec repli sur le français.
     *
     * @param  array<string, string|null>|null  $value
     */
    public static function translate(?array $value, string $locale): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = $value[$locale] ?? null;

        return is_string($text) && $text !== '' ? $text : ($value['fr'] ?? null);
    }

    public function titleIn(string $locale): string
    {
        return (string) self::translate($this->title, $locale);
    }

    public function summaryIn(string $locale): ?string
    {
        return self::translate($this->summary, $locale);
    }

    /**
     * La règle est-elle en vigueur ce jour-là (bornes comprises) ?
     */
    public function isValidOn(CarbonInterface $day): bool
    {
        $date = $day->toDateString();

        return $this->valid_from->toDateString() <= $date
            && ($this->valid_until === null || $this->valid_until->toDateString() >= $date);
    }

    /**
     * Règles montrées aux utilisateurs : vérifiées, et pas de démonstration hors du poste local.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->where('status', RuleStatus::Verified->value);

        if (! App::environment('local')) {
            $query->where('is_demo', false);
        }
    }

    /**
     * Règles en vigueur un jour donné (bornes comprises).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function validOn(Builder $query, CarbonInterface $day): void
    {
        $date = $day->toDateString();

        $query->whereDate('valid_from', '<=', $date)
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $date));
    }

    /**
     * Règles en vigueur pendant toute une période (bornes comprises).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function validThroughout(Builder $query, CarbonImmutable $from, CarbonImmutable $until): void
    {
        $query->whereDate('valid_from', '<=', $from->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $until->toDateString()));
    }

    /**
     * Règles qui chevauchent une période (bornes comprises ; $until null : sans fin).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $from, ?CarbonInterface $until): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', $from->toDateString()));

        if ($until !== null) {
            $query->whereDate('valid_from', '<=', $until->toDateString());
        }
    }
}
