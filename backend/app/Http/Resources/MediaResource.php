<?php

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Média tel que renvoyé après l'upload.
 *
 * Seul l'uuid identifie le média : ni l'id interne, ni le propriétaire, ni le disque, ni le
 * chemin de stockage ne sont exposés.
 *
 * @mixin Media
 */
class MediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'purpose' => $this->purpose->value,
            'url' => $this->url(),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
