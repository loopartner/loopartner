<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;

/**
 * Devis signalable (module Moderation, décision 45), désigné par son uuid. Contenu privé :
 * signalé par son client depuis la page du devis (POST /me/quotes/{uuid}/report), jamais par le
 * formulaire public. Masqué, le devis garde sa place pour le client sans son contenu, ne peut
 * plus être accepté et son PDF n'est plus servi au client.
 */
class QuoteModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return false;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        if ($viewer === null) {
            return null;
        }

        $quote = Quote::query()->with('lines')->where('uuid', $ref)->whereNull('moderation_restricted_at')->first();

        return $quote !== null && $quote->isClient($viewer) ? self::content($quote) : null;
    }

    public function inspect(string $ref): ?TargetContent
    {
        $quote = Quote::query()->with('lines')->where('uuid', $ref)->first();

        return $quote === null ? null : self::content($quote);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $quote = Quote::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($quote !== null) {
                Restriction::apply($quote, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $quote = Quote::query()->where('uuid', $ref)->lockForUpdate()->first();

            if ($quote !== null) {
                Restriction::lift($quote, $decisionUuid);
            }
        });
    }

    private static function content(Quote $quote): TargetContent
    {
        return new TargetContent(
            type: 'quote',
            ref: $quote->uuid,
            ownerId: $quote->provider_id,
            label: 'Devis '.($quote->number ?? '').' v'.$quote->version,
            text: TargetContent::joinText([
                'Titre' => $quote->title,
                'Objet' => $quote->scope,
                'Lignes' => $quote->lines->map(fn (QuoteLine $line): string => $line->designation.' ('.$line->quantity_milli.' millièmes × '.$line->unit_price_cents.' centimes HT)')->implode("\n"),
                'Total TTC (centimes)' => (string) $quote->total_ttc_cents,
                'Conditions de paiement' => $quote->payment_terms,
                'Remarques' => $quote->notes,
            ]),
            version: (string) ($quote->content_hash ?? TargetContent::fingerprint(['title' => $quote->title, 'scope' => $quote->scope])),
            restrictedBy: Restriction::decisionOf($quote),
            publicPath: null,
        );
    }
}
