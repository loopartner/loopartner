<?php

namespace App\Modules\Accounts\Actions;

use App\Models\User;
use App\Modules\Accounts\Support\EntitlementSet;
use InvalidArgumentException;

/**
 * Action publique du module Accounts : droits d'un utilisateur par fonctionnalité
 * (décision 5 révisée : plans "free" et "premium", avantages selon le rôle).
 *
 * - Plan : "premium" si une période premium est en cours (Action IsPremium), sinon "free".
 *   Au lot Billing, le plan viendra aussi de l'abonnement payé, sans changer cette Action.
 * - Rôle : premier rôle de l'utilisateur présent dans config('entitlements.roles') ; aucun
 *   (équipe, compte sans rôle) : aucune fonctionnalité.
 * - Valeurs : config/entitlements.php, une référence ['config' => 'cle'] étant lue au
 *   moment de l'appel.
 *
 * Utilisée par Blog (quota d'articles) et Compliance (entreprises, parcours, rappels,
 * paliers, estimation) ; GET /me/entitlements l'expose au frontend.
 */
class GetEntitlements
{
    public function __construct(private IsPremium $isPremium) {}

    public function handle(User|int $user): EntitlementSet
    {
        $user = $user instanceof User ? $user : User::query()->findOrFail($user);
        $role = $this->roleOf($user);
        $plan = $this->isPremium->handle($user->id) ? EntitlementSet::PLAN_PREMIUM : EntitlementSet::PLAN_FREE;

        return new EntitlementSet($plan, $role, $this->features($role, $plan));
    }

    /**
     * Valeurs des fonctionnalités d'un rôle pour un plan (sans regarder l'utilisateur), par
     * exemple le quota gratuit affiché à un abonné premium.
     *
     * @return array<string, int|bool|list<int>|null>
     */
    public function features(?string $role, string $plan): array
    {
        if (! in_array($plan, (array) config('entitlements.plans'), true)) {
            throw new InvalidArgumentException("Plan inconnu : {$plan}.");
        }

        if ($role === null) {
            return [];
        }

        $features = [];

        foreach ((array) config("entitlements.roles.{$role}.{$plan}", []) as $feature => $value) {
            $features[(string) $feature] = $this->resolve($value);
        }

        return $features;
    }

    private function roleOf(User $user): ?string
    {
        $roles = array_keys((array) config('entitlements.roles'));

        foreach ($user->getRoleNames() as $name) {
            if (in_array($name, $roles, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @return int|bool|list<int>|null
     */
    private function resolve(mixed $value): int|bool|array|null
    {
        if (is_array($value) && array_key_exists('config', $value)) {
            $key = (string) $value['config'];

            // Une référence mal écrite ne doit jamais devenir « illimité » (null).
            if (! config()->has($key)) {
                throw new InvalidArgumentException("Référence de configuration inconnue : {$key}.");
            }

            $value = config($key);
        }

        return match (true) {
            $value === null, is_bool($value) => $value,
            is_array($value) => array_values(array_map('intval', $value)),
            default => (int) $value,
        };
    }
}
