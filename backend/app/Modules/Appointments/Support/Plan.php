<?php

namespace App\Modules\Appointments\Support;

use App\Models\User;
use App\Modules\Accounts\Actions\GetEntitlements;
use App\Modules\Accounts\Support\EntitlementSet;
use App\Modules\Appointments\Exceptions\PremiumFeatureRequired;
use App\Modules\Appointments\Models\Calendar;
use App\Support\SecurityLog;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Quotas et avantages Premium des rendez-vous (décision 54), lus par l'Action publique
 * GetEntitlements du module Accounts (config/entitlements.php, fonctionnalités
 * "appointments.*").
 *
 * Fin du Premium : rien n'est supprimé ; les valeurs gratuites s'appliquent aux nouvelles
 * opérations (horizon, rappels, types réservables, page publique).
 */
final class Plan
{
    private const PREFIX = 'appointments.';

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
        return $this->of($user)->limit(self::PREFIX.$feature);
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
        $limit = $set->limit(self::PREFIX.$feature);

        if ($limit === null || $current < $limit) {
            return;
        }

        $this->failBeyond($user, $set, $feature, $limit, $premiumFeature, $conflictMessage);
    }

    /**
     * Vérifie une valeur demandée (horizon…) contre la limite d'une fonctionnalité.
     *
     * @throws PremiumFeatureRequired
     * @throws ConflictHttpException
     */
    public function assertAtMost(User $user, string $feature, int $value, string $premiumFeature, string $conflictMessage): void
    {
        $set = $this->of($user);
        $limit = $set->limit(self::PREFIX.$feature);

        if ($limit === null || $value <= $limit) {
            return;
        }

        $this->failBeyond($user, $set, $feature, $limit, $premiumFeature, $conflictMessage);
    }

    /**
     * Fonctionnalité booléenne ouverte (page publique…), sinon 403 premium_required.
     */
    public function assertAllows(User $user, string $feature, string $premiumFeature): void
    {
        if (! $this->of($user)->allows(self::PREFIX.$feature)) {
            $this->requirePremium($user, $premiumFeature);
        }
    }

    /**
     * Horizon de réservation appliqué : le réglage du prestataire, borné par son plan.
     */
    public function horizonDays(Calendar $calendar): int
    {
        $limit = $this->limit($calendar->user_id, 'horizon_days');

        return $limit === null ? $calendar->horizon_days : min($calendar->horizon_days, $limit);
    }

    /**
     * Délais de rappel appliqués : ceux choisis par le prestataire et permis par son plan, dans
     * la limite de leur nombre ; à défaut, les délais par défaut.
     *
     * @return list<int>
     */
    public function reminderOffsets(Calendar $calendar): array
    {
        $set = $this->of($calendar->user_id);
        $allowed = $set->choices(self::PREFIX.'reminder_offsets');
        $max = $set->limit(self::PREFIX.'max_reminder_offsets');
        $offsets = array_values(array_filter(
            array_map('intval', $calendar->reminder_offsets ?? []),
            fn (int $offset): bool => in_array($offset, $allowed, true),
        ));

        if ($max !== null) {
            $offsets = array_slice($offsets, 0, $max);
        }

        return $offsets !== [] ? $offsets : array_values(array_map('intval', (array) config('appointments.default_reminder_offsets')));
    }

    /**
     * La page de réservation publique est ouverte : activée par le prestataire et permise par
     * son plan.
     */
    public function publicPageOpen(Calendar $calendar): bool
    {
        return $calendar->public_page_enabled && $this->of($calendar->user_id)->allows(self::PREFIX.'public_booking_page');
    }

    /**
     * @throws PremiumFeatureRequired
     * @throws ConflictHttpException
     */
    private function failBeyond(User $user, EntitlementSet $set, string $feature, int $limit, string $premiumFeature, string $conflictMessage): never
    {
        $premiumFeatures = $this->entitlements->features($set->role, EntitlementSet::PLAN_PREMIUM);
        $premiumLimit = match (true) {
            ! array_key_exists(self::PREFIX.$feature, $premiumFeatures) => 0,
            $premiumFeatures[self::PREFIX.$feature] === null => PHP_INT_MAX,
            default => (int) $premiumFeatures[self::PREFIX.$feature],
        };

        if (! $set->isPremium() && $premiumLimit > $limit) {
            $this->requirePremium($user, $premiumFeature);
        }

        throw new ConflictHttpException(sprintf($conflictMessage, $limit));
    }

    /**
     * Refus 403 premium_required, journalisé avec la fonctionnalité.
     *
     * @throws PremiumFeatureRequired
     */
    public function requirePremium(User $user, string $premiumFeature): never
    {
        SecurityLog::record('appointments.premium_required', ['user_id' => $user->id, 'feature' => $premiumFeature]);

        throw new PremiumFeatureRequired($premiumFeature);
    }
}
