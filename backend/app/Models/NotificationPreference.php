<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Choix d'un utilisateur pour une catégorie de notifications et un canal (noyau). Seuls les
 * choix explicites sont enregistrés : sans ligne, la catégorie est activée.
 *
 * Aucun attribut assignable en masse : écrit par NotificationPreferenceController
 * (forceFill). Supprimé avec le compte (clé étrangère en cascade).
 *
 * @property int $id
 * @property int $user_id
 * @property string $category
 * @property string $channel
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationPreference extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
