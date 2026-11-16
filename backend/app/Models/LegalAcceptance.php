<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Acceptation datée d'une version d'un document légal par un utilisateur (noyau). Écrite par
 * App\Support\Legal\LegalDocuments::accept(), jamais modifiée ; supprimée avec le compte.
 *
 * @property int $id
 * @property int $user_id
 * @property int $legal_document_id
 * @property Carbon $accepted_at
 * @property string|null $ip_address
 */
class LegalAcceptance extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<LegalDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
