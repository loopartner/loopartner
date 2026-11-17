<?php

namespace App\Modules\Marketplace\Actions;

use App\Models\User;
use App\Modules\Directory\Actions\IsBlockedBetween;
use App\Modules\Marketplace\Enums\ClientKind;
use App\Modules\Marketplace\Enums\QuoteNotice;
use App\Modules\Marketplace\Enums\QuoteStatus;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\QuoteLine;
use App\Modules\Marketplace\Notifications\QuoteNotification;
use App\Support\SecurityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Le client accepte un devis envoyé (décision 41) : le contrat est conclu entre lui et le
 * prestataire ; Loopartner n'y est pas partie.
 *
 * - Le client envoie l'empreinte du contenu qu'il a lu (content_hash) : 409 si elle ne
 *   correspond plus (« double clic », Code civil 1127-2 : il accepte exactement ce qu'il a lu).
 * - Devis envoyé et dans sa validité (409 sinon), sans blocage entre les parties (403).
 * - Chaque texte de certification des taux utilisés doit être coché (422).
 * - Consommateur : demande expresse d'exécution pendant le délai de rétractation facultative
 *   (Code de la consommation L221-25) ; refusée pour un professionnel.
 * - Preuve : date, IP, cases cochées. Les deux parties reçoivent la confirmation, le PDF du
 *   devis accepté joint (catégorie obligatoire marketplace.quote_decisions, décision 46).
 */
class AcceptQuote
{
    public function __construct(private IsBlockedBetween $isBlockedBetween) {}

    /**
     * @param  array{content_hash: string, express_execution?: bool, certifications?: list<string>}  $data
     */
    public function handle(User $client, Quote $quote, array $data, Request $request): Quote
    {
        $quote = DB::transaction(function () use ($client, $quote, $data, $request): Quote {
            $quote = Quote::query()->with('lines')->whereKey($quote->id)->lockForUpdate()->firstOrFail();

            if ($quote->status !== QuoteStatus::Sent) {
                throw new ConflictHttpException('Ce devis ne peut plus être accepté.');
            }

            if (! $quote->isOpen()) {
                throw new ConflictHttpException('La durée de validité de ce devis est dépassée : demandez une nouvelle version au prestataire.');
            }

            if ($quote->moderation_restricted_at !== null) {
                throw new ConflictHttpException('Ce devis a été masqué par la modération et ne peut pas être accepté.');
            }

            if ($quote->provider_id === null || $this->isBlockedBetween->handle($client->id, $quote->provider_id)) {
                throw new AccessDeniedHttpException('Vous ne pouvez pas accepter ce devis.');
            }

            if ($quote->content_hash === null || ! hash_equals($quote->content_hash, $data['content_hash'])) {
                throw new ConflictHttpException('Ce devis a changé depuis votre lecture : relisez-le avant de l\'accepter.');
            }

            $required = self::requiredCertifications($quote);
            $given = array_values(array_unique($data['certifications'] ?? []));
            $missing = array_values(array_diff($required, $given));

            if ($missing !== [] || array_diff($given, $required) !== []) {
                throw ValidationException::withMessages(['certifications' => 'Cochez chacune des attestations demandées par ce devis, et seulement celles-là.']);
            }

            $express = (bool) ($data['express_execution'] ?? false);

            if ($express && $quote->client_kind !== ClientKind::Consumer) {
                throw ValidationException::withMessages(['express_execution' => 'Cette demande ne concerne qu\'un consommateur.']);
            }

            $quote->forceFill([
                'status' => QuoteStatus::Accepted,
                'accepted_at' => now(),
                'acceptance_ip' => $request->ip(),
                'acceptance_data' => ['express_execution' => $express, 'certifications' => $required],
            ])->save();

            return $quote;
        });

        QuoteNotification::sendTo($quote->provider, QuoteNotice::AcceptedForProvider, $quote);
        QuoteNotification::sendTo($client, QuoteNotice::AcceptedForClient, $quote);
        SecurityLog::record('marketplace.quote_accepted', ['quote' => $quote->uuid]);

        return $quote;
    }

    /**
     * Codes des taux dont le client certifie les conditions (texte de certification de la règle).
     *
     * @return list<string>
     */
    public static function requiredCertifications(Quote $quote): array
    {
        return $quote->lines
            ->filter(fn (QuoteLine $line): bool => is_array($line->vat_rate['client_certification'] ?? null))
            ->map(fn (QuoteLine $line): string => $line->vat_rate_code)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
