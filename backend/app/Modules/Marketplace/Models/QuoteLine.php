<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Marketplace\Enums\QuoteLineType;
use App\Modules\Marketplace\Enums\QuoteUnit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ligne d'un devis : désignation, quantité (millièmes), unité, prix unitaire HT (centimes) et
 * taux de TVA (code d'une règle vérifiée ; version, taux, libellé et mention copiés à l'envoi).
 * Les lignes sont écrites uniquement par SaveQuoteDraft (aucun champ assignable).
 *
 * @property int $id
 * @property int $quote_id
 * @property int $position
 * @property QuoteLineType $line_type
 * @property string $designation
 * @property string|null $details
 * @property int $quantity_milli
 * @property QuoteUnit $unit
 * @property int $unit_price_cents
 * @property string $vat_rate_code
 * @property int|null $vat_rate_version
 * @property int $vat_basis_points
 * @property array<string, mixed>|null $vat_rate
 * @property int $total_ht_cents
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class QuoteLine extends Model
{
    protected $table = 'marketplace_quote_lines';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'line_type' => QuoteLineType::class,
            'quantity_milli' => 'integer',
            'unit' => QuoteUnit::class,
            'unit_price_cents' => 'integer',
            'vat_rate_version' => 'integer',
            'vat_basis_points' => 'integer',
            'vat_rate' => 'array',
            'total_ht_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Quote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
