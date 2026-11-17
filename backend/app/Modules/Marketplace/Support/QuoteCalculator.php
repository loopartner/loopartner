<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Validation\ValidationException;

/**
 * Calcul d'un devis en entiers (décision 49), sans aucun taux dans le code :
 *
 * - HT d'une ligne = quantité (millièmes) × prix unitaire (centimes) / 1000, arrondi au
 *   centime le plus proche (demi-centime vers le haut) ;
 * - TVA calculée par taux, sur la somme HT des lignes de ce taux, même arrondi ;
 * - TTC = HT + TVA.
 *
 * La méthode d'arrondi est à faire valider par l'expert-comptable (docs/v1/marketplace-rules.md).
 * Les plafonds de config/marketplace.php garantissent qu'aucun produit ne dépasse PHP_INT_MAX :
 * prix ≤ 10⁹, quantité ≤ 10⁷ (produit ≤ 10¹⁶), 100 lignes, total HT ≤ 10¹¹ avant la TVA
 * (× 10⁴ points de base ≤ 10¹⁵).
 *
 * @phpstan-type CalculatedLine array{total_ht_cents: int}
 * @phpstan-type VatBreakdown list<array{code: string, basis_points: int, base_ht_cents: int, vat_cents: int}>
 * @phpstan-type QuoteTotals array{lines: list<CalculatedLine>, breakdown: VatBreakdown, total_ht_cents: int, total_vat_cents: int, total_ttc_cents: int}
 */
final class QuoteCalculator
{
    /**
     * @param  list<array{quantity_milli: int, unit_price_cents: int, vat_rate_code: string, vat_basis_points: int}>  $lines
     * @return QuoteTotals
     *
     * @throws ValidationException total HT au-delà du plafond
     */
    public static function compute(array $lines): array
    {
        $calculated = [];
        $bases = [];
        $totalHt = 0;

        foreach ($lines as $line) {
            $lineHt = self::roundedDivision($line['quantity_milli'] * $line['unit_price_cents'], 1000);
            $calculated[] = ['total_ht_cents' => $lineHt];
            $totalHt += $lineHt;

            $key = $line['vat_rate_code'];
            $bases[$key] ??= ['code' => $key, 'basis_points' => $line['vat_basis_points'], 'base_ht_cents' => 0];
            $bases[$key]['base_ht_cents'] += $lineHt;
        }

        if ($totalHt > (int) config('marketplace.quotes.max_total_cents')) {
            throw ValidationException::withMessages(['lines' => 'Le total hors taxes du devis dépasse le plafond accepté.']);
        }

        $breakdown = [];
        $totalVat = 0;

        foreach ($bases as $base) {
            $vat = self::roundedDivision($base['base_ht_cents'] * $base['basis_points'], 10_000);
            $breakdown[] = [...$base, 'vat_cents' => $vat];
            $totalVat += $vat;
        }

        return [
            'lines' => $calculated,
            'breakdown' => $breakdown,
            'total_ht_cents' => $totalHt,
            'total_vat_cents' => $totalVat,
            'total_ttc_cents' => $totalHt + $totalVat,
        ];
    }

    /**
     * Division entière arrondie au plus proche, la moitié vers le haut (valeurs positives).
     */
    public static function roundedDivision(int $numerator, int $denominator): int
    {
        return intdiv(2 * $numerator + $denominator, 2 * $denominator);
    }
}
