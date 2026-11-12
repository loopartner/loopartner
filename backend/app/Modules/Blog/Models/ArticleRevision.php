<?php

namespace App\Modules\Blog\Models;

use App\Models\Media;
use App\Models\User;
use App\Modules\Blog\Enums\RevisionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Révision d'un article publié : nouvelle version proposée par son auteur (extérieur à
 * l'équipe), appliquée seulement après la validation d'un modérateur. Le public continue de
 * voir la version validée.
 *
 * payload contient la version complète proposée (voir ArticleContent), plus l'opération sur la
 * couverture ("cover" : keep, remove ou replace avec cover_media_id). Les images du corps
 * proposées sont rattachées à la révision (table article_media). Aucun attribut n'est
 * assignable en masse (SubmitRevision).
 *
 * @property int $id
 * @property string $uuid
 * @property int $article_id
 * @property int $user_id
 * @property RevisionStatus $status
 * @property int $version
 * @property array<string, mixed> $payload
 * @property list<string> $changed_fields
 * @property int|null $cover_media_id
 * @property int|null $pending_key
 * @property Carbon|CarbonImmutable $submitted_at
 * @property Carbon|CarbonImmutable|null $decided_at
 * @property int|null $decided_by
 * @property string|null $rejection_reason
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 * @property-read Article $article
 */
class ArticleRevision extends Model
{
    public const COVER_KEEP = 'keep';

    public const COVER_REMOVE = 'remove';

    public const COVER_REPLACE = 'replace';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RevisionStatus::class,
            'payload' => 'array',
            'changed_fields' => 'array',
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Article, $this>
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nouvelle couverture proposée (opération "replace").
     *
     * @return BelongsTo<Media, $this>
     */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function isPending(): bool
    {
        return $this->status === RevisionStatus::Pending;
    }

    /**
     * Contenu proposé, sans l'opération sur la couverture.
     *
     * @return array<string, mixed>
     */
    public function content(): array
    {
        return collect($this->payload)->except('cover')->all();
    }

    public function coverOperation(): string
    {
        $operation = $this->payload['cover'] ?? self::COVER_KEEP;

        return is_string($operation) ? $operation : self::COVER_KEEP;
    }
}
