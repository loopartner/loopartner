<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Ligne du journal d'audit des décisions d'administration (écrite par App\Support\AdminAudit).
 *
 * Une ligne n'est jamais modifiée ni supprimée par le modèle : seule la purge de conservation
 * (requête de masse de AdminAudit::prune) la supprime. Aucun attribut n'est assignable en masse.
 *
 * @property int $id
 * @property int $admin_id
 * @property string $action
 * @property string $subject_type
 * @property string $subject_uuid
 * @property int|null $version
 * @property string|null $reason
 * @property array<string, mixed>|null $metadata
 * @property Carbon|CarbonImmutable|null $created_at
 */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Le journal d\'audit ne se modifie pas.'));
        static::deleting(fn (): never => throw new LogicException('Le journal d\'audit ne se supprime que par la purge de conservation.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admin_id' => 'integer',
            'version' => 'integer',
            'metadata' => 'array',
        ];
    }
}
