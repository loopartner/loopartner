<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le client refuse un devis envoyé, avec un motif facultatif (liste fermée) et un commentaire
 * facultatif, lus par le prestataire. Le prestataire est prévenu (catégorie facultative
 * marketplace.quotes) et peut proposer une nouvelle version.
 */
class DeclineQuote
{
    /**
     * @param  array{reason?: string|null, comment?: string|null}  $data
     */
    public function handle(Quote $quote, array $data): Quote
    {
        $quote = DB::transaction(function () use ($quote, $data): Quote {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Sent) {
                throw new ConflictHttpException('Seul un devis envoyé, pas encore accepté, peut être refusé.');
            }

            $quote->forceFill([
                'status' => QuoteStatus::Declined,
                'declined_at' => now(),
                'decline_reason' => $data['reason'] ?? null,
                'decline_comment' => $data['comment'] ?? null,
            ])->save();

            return $quote;
        });

        QuoteNotification::sendTo($quote->provider, QuoteNotice::Declined, $quote);
        SecurityLog::record('marketplace.quote_declined', ['quote' => $quote->uuid, 'reason' => $quote->decline_reason?->value]);

        return $quote;
    }
}
