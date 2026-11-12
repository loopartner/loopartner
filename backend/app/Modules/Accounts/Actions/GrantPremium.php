<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Accounts\Enums\PremiumSource;
use App\Modules\Accounts\Models\PremiumGrant;
use App\Support\AdminAudit;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Attribution manuelle du statut premium par un admin (en attendant le paiement).
 *
 * - Seulement aux particuliers, prestataires et entrepreneurs (422 sinon) : le statut n'a pas
 *   de sens pour l'équipe.
 * - Au plus une période en cours ou à venir par utilisateur (409), vérifié sous verrou de la
 *   ligne users : deux attributions simultanées ne se superposent pas.
 * - Journal d'audit (avec le motif) et canal security (sans motif).
 */
class GrantPremium
{
    /** @var list<string> */
    public const ELIGIBLE_ROLES = [
        AccountType::Particulier->value,
        AccountType::Prestataire->value,
        AccountType::Entrepreneur->value,
    ];

    /**
     * @throws ValidationException
     * @throws ConflictHttpException
     */
    public function handle(User $admin, User $user, ?CarbonImmutable $startsAt, ?CarbonImmutable $endsAt, string $reason): PremiumGrant
    {
        if (! $user->hasAnyRole(self::ELIGIBLE_ROLES)) {
            throw ValidationException::withMessages([
                'email' => 'Le statut premium ne s\'accorde qu\'aux particuliers, prestataires et entrepreneurs.',
            ]);
        }

        return DB::transaction(function () use ($admin, $user, $startsAt, $endsAt, $reason): PremiumGrant {
            User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (PremiumGrant::query()->currentOrUpcoming()->where('user_id', $user->id)->exists()) {
                throw new ConflictHttpException('Ce compte a déjà une période premium en cours ou à venir : retirez-la avant d\'en accorder une autre.');
            }

            $grant = new PremiumGrant;
            $grant->forceFill([
                'uuid' => (string) Str::uuid(),
                'user_id' => $user->id,
                'source' => PremiumSource::Manual,
                'starts_at' => $startsAt ?? now(),
                'ends_at' => $endsAt,
                'granted_by' => $admin->id,
            ])->save();

            AdminAudit::record($admin, 'accounts.premium.granted', 'premium_grant', $grant->uuid, null, $reason);
            SecurityLog::record('premium.granted', [
                'user_id' => $admin->id,
                'subject_user_id' => $user->id,
                'grant' => $grant->uuid,
            ]);

            return $grant;
        });
    }
}
