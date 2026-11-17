<?php

namespace App\Modules\Marketplace\Actions;

use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Support\SecurityLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Nouvelle version d'un devis envoyé, refusé ou expiré : un brouillon qui reprend son contenu,
 * garde son numéro et prend la version suivante. Le devis d'origine ne change pas ; s'il est
 * encore envoyé, l'envoi de la nouvelle version le remplacera (superseded).
 *
 * Un seul brouillon à la fois par demande (409).
 */
class ReviseQuote
{
    private const REVISABLE = [QuoteStatus::Sent, QuoteStatus::Declined, QuoteStatus::Expired];

    public function handle(Quote $quote): Quote
    {
        $revision = DB::transaction(function () use ($quote): Quote {
            $quote = Quote::query()->with('lines')->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if (! in_array($quote->status, self::REVISABLE, true)) {
                throw new ConflictHttpException('Seul un devis envoyé, refusé ou expiré peut faire l\'objet d\'une nouvelle version.');
            }

            $hasDraft = Quote::query()
                ->where('match_request_id', $quote->match_request_id)
                ->where('status', QuoteStatus::Draft->value)
                ->lockForUpdate()
                ->exists();

            if ($hasDraft) {
                throw new ConflictHttpException('Un brouillon de devis existe déjà pour cette demande.');
            }

            $latestVersion = (int) Quote::query()->where('match_request_id', $quote->match_request_id)->where('number', $quote->number)->max('version');

            $revision = $quote->replicate([
                'uuid', 'status', 'issuer_snapshot', 'content_hash', 'valid_until', 'expires_at', 'sent_at',
                'accepted_at', 'acceptance_ip', 'acceptance_data', 'declined_at', 'decline_reason', 'decline_comment',
                'withdrawn_at', 'expired_at', 'superseded_at', 'withdrawal_exercised_at',
                'moderation_restricted_at', 'moderation_decision_uuid',
            ]);
            $revision->forceFill([
                'uuid' => (string) Str::uuid(),
                'status' => QuoteStatus::Draft,
                'version' => $latestVersion + 1,
                'revision_of_id' => $quote->id,
                'start_date' => $quote->start_date?->isPast() ? null : $quote->start_date,
            ])->save();

            foreach ($quote->lines as $line) {
                $copy = $line->replicate(['quote_id', 'vat_rate_version', 'vat_rate']);
                $copy->forceFill(['quote_id' => $revision->id])->save();
            }

            return $revision->load('lines');
        });

        SecurityLog::record('marketplace.quote_revised', ['quote' => $revision->uuid, 'revision_of' => $quote->uuid]);

        return $revision;
    }
}
