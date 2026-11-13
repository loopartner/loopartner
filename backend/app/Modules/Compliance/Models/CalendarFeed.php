<?php

namespace App\Modules\Compliance\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Abonnement privé au calendrier des échéances (Google Agenda, Outlook…), un par utilisateur.
 *
 * Le jeton de l'URL (sélecteur + secret) n'est jamais stocké : seulement le sélecteur et
 * l'empreinte HMAC-SHA256 du secret (CalendarFeedToken). Révocable et régénérable.
 *
 * @property int $id
 * @property int $user_id
 * @property string $selector
 * @property string $verifier_hash
 * @property CarbonInterface|null $last_accessed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class CalendarFeed extends Model
{
    protected $table = 'compliance_calendar_feeds';

    /**
     * @var list<string>
     */
    protected $hidden = ['verifier_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_accessed_at' => 'datetime',
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
