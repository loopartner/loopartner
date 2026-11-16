<?php

namespace App\Modules\Accounts\Support;

/**
 * Droits d'un utilisateur, renvoyés par l'Action publique GetEntitlements : plan actuel
 * ("free" ou "premium"), rôle pris en compte, et valeur de chaque fonctionnalité pour ce plan.
 *
 * Objet de données simple, sans modèle Eloquent : les autres modules peuvent le lire.
 * Une fonctionnalité absente n'est pas ouverte (limite 0, false, liste vide).
 */
final readonly class EntitlementSet
{
    public const PLAN_FREE = 'free';

    public const PLAN_PREMIUM = 'premium';

    /**
     * @param  array<string, int|bool|list<int>|null>  $features
     */
    public function __construct(
        public string $plan,
        public ?string $role,
        public array $features,
    ) {}

    public function isPremium(): bool
    {
        return $this->plan === self::PLAN_PREMIUM;
    }

    public function has(string $feature): bool
    {
        return array_key_exists($feature, $this->features);
    }

    /**
     * Limite d'une fonctionnalité : null = illimitée ; absente = 0.
     */
    public function limit(string $feature): ?int
    {
        if (! $this->has($feature)) {
            return 0;
        }

        $value = $this->features[$feature];

        return $value === null ? null : (int) $value;
    }

    /**
     * Fonctionnalité ouverte (booléen vrai, ou limite non nulle).
     */
    public function allows(string $feature): bool
    {
        if (! $this->has($feature)) {
            return false;
        }

        $value = $this->features[$feature];

        return match (true) {
            $value === null => true,
            is_bool($value) => $value,
            is_int($value) => $value > 0,
            default => $value !== [],
        };
    }

    /**
     * Choix proposés (liste d'entiers), vide si la fonctionnalité est absente.
     *
     * @return list<int>
     */
    public function choices(string $feature): array
    {
        $value = $this->features[$feature] ?? [];

        return is_array($value) ? $value : [];
    }

    /**
     * @return array{plan: string, role: string|null, features: array<string, int|bool|list<int>|null>}
     */
    public function toArray(): array
    {
        return [
            'plan' => $this->plan,
            'role' => $this->role,
            'features' => $this->features,
        ];
    }
}
