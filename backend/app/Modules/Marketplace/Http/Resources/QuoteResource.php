<?php

namespace App\Modules\Marketplace\Http\Resources;

use App\Models\User;
use App\Modules\Accounts\Actions\GetProviderSummaries;
use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use App\Support\Locale;
use App\Support\Moderation\Restriction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Devis vu par l'une de ses deux parties (viewer_role : provider ou client). Ni id interne ni
 * identifiant d'utilisateur ; aucun email ni téléphone d'un utilisateur, seulement les
 * coordonnées professionnelles de l'émetteur copiées dans le devis (mentions obligatoires).
 *
 * - Le client voit le prestataire par son profil public ; le prestataire voit le bloc client
 *   qu'il a rédigé.
 * - Un devis masqué par la modération garde sa place pour le client, sans son contenu.
 * - mentions : mentions de TVA imprimées, certifications à cocher à l'acceptation, droit de
 *   rétractation d'un consommateur et rappel que Loopartner n'est pas partie au contrat.
 *
 * @mixin Quote
 */
class QuoteResource extends JsonResource
{
    /**
     * @var array<string, string>
     */
    private const NOT_A_PARTY = [
        'fr' => 'Loopartner met en relation les parties et fournit l\'outil de devis, mais n\'est pas partie au contrat conclu entre le prestataire et le client.',
        'en' => 'Loopartner connects the parties and provides the quote tool, but is not a party to the contract between the provider and the client.',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $isProvider = $this->isProvider($viewer);
        $hidden = ! $isProvider && $this->moderation_restricted_at !== null;
        $locale = Locale::fromRequest($request);

        return [
            'uuid' => $this->uuid,
            'number' => $this->number,
            'version' => $this->version,
            'status' => $this->status->value,
            'viewer_role' => $isProvider ? 'provider' : 'client',
            'client_kind' => $this->client_kind->value,
            'title' => $this->title,
            'scope' => $hidden ? null : $this->scope,
            'offer' => $this->offer_title === null ? null : ['title' => $this->offer_title],
            'provider' => $isProvider ? null : $this->providerSummary(),
            'issuer' => $hidden ? null : $this->issuer_snapshot?->getArrayCopy(),
            'client' => $hidden ? null : $this->client_snapshot->getArrayCopy(),
            'currency' => $this->currency,
            'lines' => $hidden ? null : $this->lines->map(fn (QuoteLine $line): array => [
                'position' => $line->position,
                'line_type' => $line->line_type->value,
                'designation' => $line->designation,
                'details' => $line->details,
                'quantity_milli' => $line->quantity_milli,
                'unit' => $line->unit->value,
                'unit_price_cents' => $line->unit_price_cents,
                'vat' => [
                    'code' => $line->vat_rate_code,
                    'basis_points' => $line->vat_basis_points,
                    'label' => $line->vat_rate['label'] ?? null,
                ],
                'total_ht_cents' => $line->total_ht_cents,
            ])->values()->all(),
            'totals' => $hidden ? null : [
                'ht_cents' => $this->total_ht_cents,
                'vat_cents' => $this->total_vat_cents,
                'ttc_cents' => $this->total_ttc_cents,
                'vat_breakdown' => $this->vat_breakdown ?? [],
            ],
            'validity' => [
                'days' => $this->validity_days,
                'valid_until' => $this->valid_until?->toDateString(),
                'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            ],
            'start_date' => $this->start_date?->toDateString(),
            'duration' => $this->duration,
            'payment_terms' => $hidden ? null : $this->payment_terms,
            'deposit' => $this->deposit_cents === null ? null : ['cents' => $this->deposit_cents, 'kind' => $this->deposit_kind?->value],
            'quote_fee' => ['is_free' => $this->quote_is_free, 'cents' => $this->quote_fee_cents],
            'keep_replaced_parts' => $this->keep_replaced_parts,
            'notes' => $hidden ? null : $this->notes,
            'mentions' => [
                'vat' => $this->vatMentions(),
                'certifications' => $this->certifications(),
                'consumer' => $this->client_kind === ClientKind::Consumer ? [
                    'withdrawal_days' => (int) config('marketplace.withdrawal_days'),
                    'withdrawal_ends_at' => $this->withdrawalEndsAt()?->toIso8601ZuluString(),
                ] : null,
                'not_a_party' => self::NOT_A_PARTY[$locale] ?? self::NOT_A_PARTY['fr'],
            ],
            'content_hash' => $this->content_hash,
            'decline' => $this->status === QuoteStatus::Declined ? [
                'reason' => $this->decline_reason?->value,
                'comment' => $this->decline_comment,
            ] : null,
            'acceptance' => $this->accepted_at === null ? null : [
                'accepted_at' => $this->accepted_at->toIso8601ZuluString(),
                'express_execution' => (bool) ($this->acceptance_data['express_execution'] ?? false),
                'certifications' => $this->acceptance_data['certifications'] ?? [],
                'withdrawal_exercised_at' => $this->withdrawal_exercised_at?->toIso8601ZuluString(),
            ],
            'moderation' => Restriction::view($this->resource),
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'declined_at' => $this->declined_at?->toIso8601ZuluString(),
            'withdrawn_at' => $this->withdrawn_at?->toIso8601ZuluString(),
            'expired_at' => $this->expired_at?->toIso8601ZuluString(),
            'superseded_at' => $this->superseded_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Résumé public du prestataire (Action publique du module Accounts), ou {deleted: true}.
     *
     * @return array<string, mixed>
     */
    private function providerSummary(): array
    {
        if ($this->provider_id === null) {
            return ['deleted' => true];
        }

        return app(GetProviderSummaries::class)->handle([$this->provider_id])[$this->provider_id] ?? ['deleted' => true];
    }

    /**
     * Mentions de TVA des taux utilisés (franchise, autoliquidation…), sans doublon.
     *
     * @return list<array{code: string, text: array<string, string|null>}>
     */
    private function vatMentions(): array
    {
        $mentions = [];

        foreach ($this->lines as $line) {
            $mention = $line->vat_rate['mention'] ?? null;

            if (is_array($mention)) {
                $mentions[$line->vat_rate_code] = ['code' => $line->vat_rate_code, 'text' => $mention];
            }
        }

        return array_values($mentions);
    }

    /**
     * Textes que le client certifie à l'acceptation (taux réduits des travaux dans les
     * logements), sans doublon : il les coche, sinon l'acceptation est refusée.
     *
     * @return list<array{code: string, text: array<string, string|null>}>
     */
    private function certifications(): array
    {
        $certifications = [];

        foreach ($this->lines as $line) {
            $text = $line->vat_rate['client_certification'] ?? null;

            if (is_array($text)) {
                $certifications[$line->vat_rate_code] = ['code' => $line->vat_rate_code, 'text' => $text];
            }
        }

        return array_values($certifications);
    }
}
