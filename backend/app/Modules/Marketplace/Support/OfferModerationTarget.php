<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Marketplace\Models\Offer;
use App\Support\Moderation\ModerationTarget;
use App\Support\Moderation\Restriction;
use App\Support\Moderation\TargetContent;
use Illuminate\Support\Facades\DB;

/**
 * Offre signalable (module Moderation), désignée par son slug, stable. Masquée, elle disparaît
 * de la recherche, de sa page et de la page du prestataire (scope visible()), sans changer de
 * statut.
 */
class OfferModerationTarget implements ModerationTarget
{
    public function publiclyReportable(): bool
    {
        return true;
    }

    public function findVisible(string $ref, ?User $viewer): ?TargetContent
    {
        $offer = Offer::query()->visible()->where('slug', $ref)->first();

        return $offer === null ? null : self::content($offer);
    }

    public function inspect(string $ref): ?TargetContent
    {
        $offer = Offer::query()->where('slug', $ref)->first();

        return $offer === null ? null : self::content($offer);
    }

    public function restrict(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $offer = Offer::query()->where('slug', $ref)->lockForUpdate()->first();

            if ($offer !== null) {
                Restriction::apply($offer, $decisionUuid);
            }
        });
    }

    public function lift(string $ref, string $decisionUuid): void
    {
        DB::transaction(function () use ($ref, $decisionUuid): void {
            $offer = Offer::query()->where('slug', $ref)->lockForUpdate()->first();

            if ($offer !== null) {
                Restriction::lift($offer, $decisionUuid);
            }
        });
    }

    private static function content(Offer $offer): TargetContent
    {
        return new TargetContent(
            type: 'offer',
            ref: (string) $offer->slug,
            ownerId: $offer->user_id,
            label: $offer->title,
            text: TargetContent::joinText([
                'Titre' => $offer->title,
                'Résumé' => $offer->summary,
                'Description' => $offer->description,
                'Prix indicatif TTC (centimes)' => $offer->price_cents === null ? 'sur devis' : (string) $offer->price_cents,
                'Ville' => $offer->city,
                'Pays' => $offer->country,
            ]),
            version: TargetContent::fingerprint([
                'title' => $offer->title,
                'summary' => $offer->summary,
                'description' => $offer->description,
                'price' => [$offer->pricing_mode->value, $offer->price_cents],
                'place' => [$offer->delivery_mode->value, $offer->country, $offer->city],
            ]),
            restrictedBy: Restriction::decisionOf($offer),
            publicPath: '/offers/'.$offer->slug,
        );
    }
}
