<?php

namespace App\Support\Ai;

/**
 * Coût d'un appel en micro-unités de la devise du fournisseur (entiers, aucun décimal), à
 * partir des prix par million de jetons de config/ai.php (pricing). Arrondi au micro supérieur.
 */
final class AiPricing
{
    public static function has(string $model): bool
    {
        $price = config('ai.pricing.'.$model);

        return is_array($price) && is_int($price['input'] ?? null) && is_int($price['output'] ?? null);
    }

    public static function cost(string $model, int $inputTokens, int $outputTokens): int
    {
        /** @var array{input: int, output: int} $price */
        $price = config('ai.pricing.'.$model, ['input' => 0, 'output' => 0]);
        $total = max(0, $inputTokens) * $price['input'] + max(0, $outputTokens) * $price['output'];

        return intdiv($total + 999_999, 1_000_000);
    }

    /**
     * Plafond configuré en cents, converti en micro-unités.
     */
    public static function centsToMicros(int $cents): int
    {
        return max(0, $cents) * 10_000;
    }
}
