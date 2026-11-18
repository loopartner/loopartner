<?php

namespace App\Modules\Workspaces\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Accounts\Support\EntitlementSet;
use App\Modules\Workspaces\Exceptions\PremiumFeatureRequired;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Quotas et avantages Premium des workspaces et du copilote (décisions 61 et 63), lus par
 * l'Action publique GetEntitlements du module Accounts (config/entitlements.php,
 * fonctionnalités "workspaces.*" et "copilot.*").
 */
final class Plan
{
    public function __construct(private GetEntitlements $entitlements) {}

    public function of(User|int $user): EntitlementSet
    {
        return $this->entitlements->handle($user);
    }

    /**
     * Limite d'une fonctionnalité (null : illimitée).
     */
    public function limit(User|int $user, string $feature): ?int
    {
        return $this->of($user)->limit($feature);
    }

    public function allows(User|int $user, string $feature): bool
    {
        return $this->of($user)->allows($feature);
    }

    public function isPremium(User|int $user): bool
    {
        return $this->of($user)->isPremium();
    }

    /**
     * Vérifie qu'un compte reste sous la limite d'une fonctionnalité avant d'en ajouter un.
     * Au-delà : 403 premium_required si le Premium permettrait davantage, sinon 409 avec le
     * message donné (%d : la limite).
     *
     * @throws PremiumFeatureRequired
     * @throws ConflictHttpException
     */
    public function assertBelow(User $user, string $feature, int $current, string $premiumFeature, string $conflictMessage): void
    {
        $set = $this->of($user);
        $limit = $set->limit($feature);

        if ($limit === null || $current < $limit) {
            return;
        }

        if (! $set->isPremium() && $this->premiumAllowsMore($set, $feature, $limit)) {
            $this->requirePremium($user, $premiumFeature);
        }

        throw new ConflictHttpException(sprintf($conflictMessage, $limit));
    }

    /**
     * Le Premium du même rôle permet-il davantage que la limite donnée ?
     */
    public function premiumAllowsMore(EntitlementSet $set, string $feature, int $limit): bool
    {
        $premium = $this->entitlements->features($set->role, EntitlementSet::PLAN_PREMIUM);

        return array_key_exists($feature, $premium) && ($premium[$feature] === null || (is_int($premium[$feature]) && $premium[$feature] > $limit));
    }

    /**
     * Refus 403 premium_required, journalisé avec la fonctionnalité.
     *
     * @throws PremiumFeatureRequired
     */
    public function requirePremium(User $user, string $feature): never
    {
        SecurityLog::record('workspaces.premium_required', ['user_id' => $user->id, 'feature' => $feature]);

        throw new PremiumFeatureRequired($feature);
    }
}
