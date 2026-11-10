<?php

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use App\Support\Media\MediaPurpose;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ligne de média, sans fichier. Utiliser withFile() pour écrire aussi un fichier sur le disque.
 *
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $uuid = (string) Str::uuid();

        return [
            'uuid' => $uuid,
            'user_id' => User::factory(),
            'purpose' => MediaPurpose::Avatar,
            'disk' => 'public',
            'path' => 'avatar/'.now()->format('Y/m').'/'.$uuid.'.webp',
            'mime_type' => 'image/webp',
            'size' => 1024,
            'width' => 512,
            'height' => 512,
            'attached_at' => null,
        ];
    }

    public function purpose(MediaPurpose $purpose): static
    {
        return $this->state(fn (array $attributes): array => [
            'purpose' => $purpose,
            'path' => $purpose->value.'/'.now()->format('Y/m').'/'.$attributes['uuid'].'.webp',
        ]);
    }

    public function attached(): static
    {
        return $this->state(fn (): array => ['attached_at' => now()]);
    }

    /**
     * Écrit un fichier factice à l'emplacement du média.
     */
    public function withFile(): static
    {
        return $this->afterCreating(function (Media $media): void {
            Storage::disk($media->disk)->put($media->path, 'webp');
        });
    }
}
