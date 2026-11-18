<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un appel à l'IA (noyau, lot 5) : fonctionnalité, fournisseur, modèle, jetons, coût réservé et
 * coût réel, statut (reserved, succeeded, failed). **Jamais le contenu envoyé ni la réponse.**
 * Écrit uniquement par App\Support\Ai\AiGateway (forceFill) ; détaché du compte à sa
 * suppression ; purgé par ai:prune.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property string $feature
 * @property string $provider
 * @property string $model
 * @property string $status
 * @property string|null $failure_reason
 * @property int $input_tokens
 * @property int $output_tokens
 * @property int $reserved_micros
 * @property int $cost_micros
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AiUsage extends Model
{
    public const STATUS_RESERVED = 'reserved';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reserved_micros' => 'integer',
            'cost_micros' => 'integer',
        ];
    }
}
