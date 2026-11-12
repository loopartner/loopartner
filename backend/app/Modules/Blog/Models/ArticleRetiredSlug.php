<?php

namespace App\Modules\Blog\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Slug d'un article supprimé après publication : GET /articles/{slug} répond 410, et aucun
 * autre article ne le reprend pendant la durée de conservation.
 *
 * @property int $id
 * @property string $slug
 * @property Carbon|CarbonImmutable $retired_at
 */
class ArticleRetiredSlug extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }
}
