<?php

namespace App\Modules\Blog\Models;

use App\Models\Media;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Blog\Enums\ArticleStatus;
use App\Modules\Blog\Enums\AuthorKind;
use App\Modules\Blog\Enums\RevisionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Blog\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Article du blog.
 *
 * - uuid identifie l'article pour son auteur et les modérateurs ; le public ne voit que le
 *   slug, fixé à la première publication et jamais modifié.
 * - Aucun attribut n'est assignable en masse : le contenu passe par ArticleContent::apply() et
 *   le cycle de vie (statut, version, slug, dates, couverture, compteurs) par les Actions.
 * - body est le document normalisé par ArticleBody (jamais l'entrée brute du client).
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $slug
 * @property int|null $user_id
 * @property AuthorKind $author_kind
 * @property ArticleStatus $status
 * @property int $version
 * @property string $language
 * @property string $title
 * @property string|null $excerpt
 * @property array{version: int, blocks: list<array<string, mixed>>}|null $body
 * @property string|null $author_name
 * @property string|null $seo_title
 * @property string|null $seo_description
 * @property int|null $cover_media_id
 * @property int $word_count
 * @property int $reading_time_minutes
 * @property int $submission_count
 * @property Carbon|CarbonImmutable|null $first_submitted_at
 * @property Carbon|CarbonImmutable|null $submitted_at
 * @property Carbon|CarbonImmutable|null $published_at
 * @property Carbon|CarbonImmutable|null $content_updated_at
 * @property Carbon|CarbonImmutable|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|CarbonImmutable|null $unpublished_at
 * @property string|null $unpublication_reason
 * @property Carbon|CarbonImmutable|null $moderation_restricted_at
 * @property string|null $moderation_decision_uuid
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[UseFactory(ArticleFactory::class)]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'version' => 1,
        'word_count' => 0,
        'reading_time_minutes' => 1,
        'submission_count' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'moderation_restricted_at' => 'datetime',
            'status' => ArticleStatus::class,
            'author_kind' => AuthorKind::class,
            'body' => 'array',
            'version' => 'integer',
            'word_count' => 'integer',
            'reading_time_minutes' => 'integer',
            'submission_count' => 'integer',
            'first_submitted_at' => 'datetime',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'content_updated_at' => 'datetime',
            'rejected_at' => 'datetime',
            'unpublished_at' => 'datetime',
        ];
    }

    /**
     * Auteur (null pour un article de l'équipe dont le compte a été supprimé).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    /**
     * Catégories de services (modèle du noyau).
     *
     * @return BelongsToMany<ServiceCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'article_category')
            ->orderBy('service_categories.position')
            ->orderBy('service_categories.slug');
    }

    /**
     * @return HasMany<ArticleRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(ArticleRevision::class);
    }

    /**
     * Révision en attente de validation (au plus une).
     *
     * @return HasOne<ArticleRevision, $this>
     */
    public function pendingRevision(): HasOne
    {
        return $this->hasOne(ArticleRevision::class)->where('status', RevisionStatus::Pending->value);
    }

    /**
     * Dernière révision, quel que soit son statut (pour afficher un refus à l'auteur).
     *
     * @return HasOne<ArticleRevision, $this>
     */
    public function latestRevision(): HasOne
    {
        return $this->hasOne(ArticleRevision::class)->latestOfMany();
    }

    public function isPublished(): bool
    {
        return $this->status === ArticleStatus::Published;
    }

    /**
     * Articles visibles du public.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published->value)
            ->whereNull('moderation_restricted_at');
    }

    /**
     * Masqué par la modération (module Moderation) : absent de la lecture publique (404) jusqu'à
     * la levée de la restriction ; son statut ne change pas.
     */
    public function isRestricted(): bool
    {
        return $this->moderation_restricted_at !== null;
    }
}
