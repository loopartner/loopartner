<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement unique à l'agenda d'un utilisateur (URL privée à jeton, App\Support\Calendar\
 * FeedToken). Seule l'empreinte du secret est stockée ; tout est fixé par le noyau
 * (forceFill), rien n'est assignable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $selector
 * @property string $verifier_hash
 * @property CarbonInterface|null $last_accessed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property-read User|null $user
 */
class AgendaFeed extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @var list<string>
     */
    protected $hidden = ['verifier_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['last_accessed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
