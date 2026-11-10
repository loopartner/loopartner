<?php

namespace App\Modules\Directory\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Blocage d'un utilisateur par un autre. Il vaut dans les deux sens : plus aucun message entre
 * eux, ni aucune nouvelle demande, quel que soit le type de demande ou le projet.
 * Aucun attribut n'est assignable en masse (BlockUser).
 *
 * @property int $id
 * @property int $blocker_id
 * @property int $blocked_id
 * @property Carbon|CarbonImmutable|null $created_at
 */
class UserBlock extends Model
{
    public const UPDATED_AT = null;

    /**
     * L'un des deux utilisateurs a-t-il bloqué l'autre ?
     */
    public static function existsBetween(int $userId, int $otherUserId): bool
    {
        return self::query()
            ->where(fn (Builder $query) => $query->where('blocker_id', $userId)->where('blocked_id', $otherUserId))
            ->orWhere(fn (Builder $query) => $query->where('blocker_id', $otherUserId)->where('blocked_id', $userId))
            ->exists();
    }

    /**
     * L'utilisateur $blockerId a-t-il bloqué $blockedId ?
     */
    public static function isBlockedBy(int $blockedId, int $blockerId): bool
    {
        return self::query()->where('blocker_id', $blockerId)->where('blocked_id', $blockedId)->exists();
    }
}
