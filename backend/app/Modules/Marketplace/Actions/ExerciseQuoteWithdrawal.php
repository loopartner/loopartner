<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Fonction de rétractation en ligne (Code de la consommation L221-21, décision 42) : un
 * consommateur qui a accepté un devis se rétracte dans les marketplace.withdrawal_days jours
 * qui suivent l'acceptation. Loopartner enregistre la rétractation et prévient le prestataire ;
 * les conséquences (remboursement, prestation déjà exécutée à sa demande expresse : L221-25,
 * L221-28) relèvent des parties. À faire valider par le juriste.
 */
class ExerciseQuoteWithdrawal
{
    public function handle(Quote $quote): Quote
    {
        $quote = DB::transaction(function () use ($quote): Quote {
            $quote = Quote::query()->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Accepted || $quote->client_kind !== ClientKind::Consumer) {
                throw new ConflictHttpException('Ce devis n\'ouvre pas de droit de rétractation.');
            }

            if ($quote->withdrawal_exercised_at !== null) {
                throw new ConflictHttpException('Vous vous êtes déjà rétracté pour ce devis.');
            }

            $endsAt = $quote->withdrawalEndsAt();

            if ($endsAt === null || $endsAt->isPast()) {
                throw new ConflictHttpException('Le délai de rétractation est dépassé.');
            }

            $quote->forceFill(['withdrawal_exercised_at' => now()])->save();

            return $quote;
        });

        QuoteNotification::sendTo($quote->provider, QuoteNotice::WithdrawalForProvider, $quote);
        QuoteNotification::sendTo($quote->client, QuoteNotice::WithdrawalForClient, $quote);
        SecurityLog::record('marketplace.quote_withdrawal_exercised', ['quote' => $quote->uuid]);

        return $quote;
    }
}
