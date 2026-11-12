<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\PremiumSource;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retrait d'une période premium manuelle par un admin, avec motif (journal d'audit).
 *
 * 409 si la période est déjà retirée ou terminée, ou si elle vient d'un paiement (elle se
 * gérera par le prestataire de paiement : remboursement, résiliation).
 */
class RevokePremium
{
    /**
     * @throws ConflictHttpException
     */
    public function handle(User $admin, PremiumGrant $grant, string $reason): PremiumGrant
    {
        return DB::transaction(function () use ($admin, $grant, $reason): PremiumGrant {
            $grant = PremiumGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();

            if ($grant->source === PremiumSource::Payment) {
                throw new ConflictHttpException('Cette période vient d\'un paiement : elle se gère par le paiement.');
            }

            if (! $grant->isCurrentOrUpcoming()) {
                throw new ConflictHttpException('Cette période premium est déjà terminée ou retirée.');
            }

            $grant->forceFill(['revoked_at' => now(), 'revoked_by' => $admin->id])->save();

            AdminAudit::record($admin, 'accounts.premium.revoked', 'premium_grant', $grant->uuid, null, $reason);
            SecurityLog::record('premium.revoked', [
                'user_id' => $admin->id,
                'subject_user_id' => $grant->user_id,
                'grant' => $grant->uuid,
            ]);

            return $grant;
        });
    }
}
