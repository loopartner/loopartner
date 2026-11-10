<?php

namespace App\Modules\Accounts\Models;

use App\Models\Media;
use App\Models\User;
use App\Modules\Accounts\Enums\ProviderProfileStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Modules\Accounts\ProviderProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Profil public d'un prestataire.
 *
 * Ce modèle est interne au module Accounts : les autres modules ne l'importent jamais et
 * passent par une Action publique du module. Le slug et user_id ne sont pas assignables
 * en masse : ils sont fixés une seule fois, à la création, par UpsertProviderProfile.
 * logo_media_id ne l'est pas non plus : il est fixé par MediaLibrary::replace().
 *
 * @property int $id
 * @property int $user_id
 * @property string $display_name
 * @property string $slug
 * @property string|null $headline
 * @property string|null $bio
 * @property string|null $city
 * @property string|null $country
 * @property string|null $website
 * @property int|null $logo_media_id
 * @property ProviderProfileStatus $status
 * @property Carbon|CarbonImmutable|null $published_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
#[UseFactory(ProviderProfileFactory::class)]
#[Fillable(['display_name', 'headline', 'bio', 'city', 'country', 'website', 'status', 'published_at'])]
class ProviderProfile extends Model
{
    /** @use HasFactory<ProviderProfileFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProviderProfileStatus::class,
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Logo (média du noyau App\Models\Media, partagé par tous les modules).
     *
     * @return BelongsTo<Media, $this>
     */
    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    public function isPublished(): bool
    {
        return $this->status === ProviderProfileStatus::Published;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', ProviderProfileStatus::Published->value);
    }
}
