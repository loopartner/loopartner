<?php

namespace App\Models;

use App\Support\Media\MediaPurpose;
use Carbon\CarbonImmutable;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Média (image) partagé : tous les modules ont le droit de l'utiliser.
 *
 * Toutes les opérations (upload, rattachement, suppression) passent par
 * App\Support\Media\MediaLibrary, qui supprime aussi les fichiers. Aucun attribut n'est
 * assignable en masse. Seuls uuid et l'URL publique sont exposés par l'API.
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property MediaPurpose $purpose
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $size
 * @property int $width
 * @property int $height
 * @property Carbon|CarbonImmutable|null $attached_at
 * @property Carbon|CarbonImmutable|null $created_at
 * @property Carbon|CarbonImmutable|null $updated_at
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purpose' => MediaPurpose::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'attached_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isAttached(): bool
    {
        return $this->attached_at !== null;
    }

    /**
     * URL publique du fichier, sur le disque où il a été enregistré.
     */
    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * Médias jamais rattachés.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereNull('attached_at');
    }
}
