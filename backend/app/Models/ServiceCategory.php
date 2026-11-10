<?php

namespace App\Models;

use App\Support\Locale;
use Carbon\CarbonImmutable;
use Database\Factories\ServiceCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Catégorie de services, partagée par tous les modules (comme User et Media).
 *
 * - Deux niveaux au maximum : une catégorie parente et ses sous-catégories.
 * - Le nom est traduit ({"fr": "…", "en": "…"}), avec repli sur le français.
 * - Seul le slug est exposé par l'API, jamais l'id.
 * - Une catégorie ne se supprime pas : elle se désactive. Une sous-catégorie dont le parent
 *   est désactivé est considérée comme inactive (scope active()).
 *
 * @property int $id
 * @property int|null $parent_id
 * @property string $slug
 * @property array<string, string> $name
 * @property int $position
 * @property bool $is_active
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[Fillable(['parent_id', 'slug', 'name', 'position', 'is_active'])]
class ServiceCategory extends Model
{
    /** @use HasFactory<ServiceCategoryFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'position' => 0,
        'is_active' => true,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            if ($category->parent_id === null) {
                return;
            }

            if ($category->exists && $category->parent_id === $category->id) {
                throw new LogicException('Une catégorie ne peut pas être son propre parent.');
            }

            $parent = self::query()->find($category->parent_id);

            if ($parent?->parent_id !== null) {
                throw new LogicException('Les catégories de services ont deux niveaux au maximum.');
            }

            if ($category->exists && $category->children()->exists()) {
                throw new LogicException('Une catégorie qui a des sous-catégories ne peut pas avoir de parent.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name' => 'array',
            'position' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Nom dans la langue demandée, avec repli sur le français puis sur le slug.
     */
    public function nameIn(string $locale): string
    {
        return $this->name[$locale] ?? $this->name[Locale::DEFAULT] ?? $this->slug;
    }

    /**
     * Ids de la catégorie active portant ce slug et de ses sous-catégories actives : un filtre
     * sur une catégorie parente inclut ses sous-catégories. Liste vide si le slug est inconnu
     * ou inactif.
     *
     * @return list<int>
     */
    public static function idsWithDescendants(string $slug): array
    {
        $category = self::query()->active()->where('slug', $slug)->first();

        if ($category === null) {
            return [];
        }

        /** @var list<int> $children */
        $children = $category->children()->where('is_active', true)->pluck('id')->all();

        return [$category->id, ...$children];
    }

    /**
     * Catégories actives dont le parent éventuel est lui aussi actif.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)
            ->where(function (Builder $query): void {
                $query->whereNull('parent_id')
                    ->orWhereHas('parent', fn (Builder $parent) => $parent->where('is_active', true));
            });
    }

    /**
     * Ordre d'affichage : position, puis slug.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('slug');
    }
}
