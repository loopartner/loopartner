<?php

namespace App\Modules\Blog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Première soumission d'un article d'un prestataire ou d'un entrepreneur, comptée dans le
 * quota gratuit (ArticleQuota). La ligne survit à la suppression de l'article.
 *
 * @property int $id
 * @property int $user_id
 * @property string $article_uuid
 * @property Carbon|CarbonImmutable $counted_at
 */
class ArticleQuotaEntry extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['counted_at' => 'datetime'];
    }
}
