<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le prestataire retire un devis envoyé, avant son acceptation (l'offre de contrat n'existe
 * plus). Un devis accepté ne se retire pas.
 */
class WithdrawQuote
{
    public function handle(Quote $quote): Quote
    {
        $quote = DB::transaction(function () use ($quote): Quote {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Sent) {
                throw new ConflictHttpException('Seul un devis envoyé, pas encore accepté, peut être retiré.');
            }

            $quote->forceFill(['status' => QuoteStatus::Withdrawn, 'withdrawn_at' => now()])->save();

            return $quote;
        });

        SecurityLog::record('marketplace.quote_withdrawn', ['quote' => $quote->uuid]);

        return $quote;
    }
}
