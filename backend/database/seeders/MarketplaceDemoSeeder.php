<?php

namespace Database\Seeders;

use App\Models\ServiceCategory;
use App\Models\User;
use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Marketplace\Actions\PublishOffer;
use App\Modules\Marketplace\Actions\SaveOffer;
use App\Modules\Marketplace\Actions\SaveQuoteDraft;
use App\Modules\Marketplace\Actions\SendQuote;
use App\Modules\Marketplace\Models\Offer;
use App\Modules\Marketplace\Models\Quote;
use App\Modules\Marketplace\Models\SellerProfile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Données de démonstration du module Marketplace, appelées par DatabaseSeeder UNIQUEMENT si
 * DemoDataGuard l'autorise (poste local). Aucun email.
 *
 * - Taux de TVA FICTIF (règle Compliance is_demo, code demo.vat.rate.standard, titre
 *   « [Démo] », valeur ronde sans rapport avec un taux réel) : utilisable seulement en local.
 * - prestataire@example.com : identité professionnelle fictive (SIREN de forme valide qui ne
 *   désigne aucune entreprise réelle connue), deux offres publiées, et un devis envoyé au
 *   particulier dans leur mise en relation acceptée (personnalisé : le prestataire est Premium).
 *
 * Idempotent : la règle est repérée par son code, l'identité par l'utilisateur, les offres par
 * leur titre et le devis par sa demande de mise en relation.
 */
class MarketplaceDemoSeeder extends Seeder
{
    public const DEMO_VAT_CODE = 'demo.vat.rate.standard';

    /**
     * Taux fictif en points de base (valeur ronde de démonstration, aucun taux réel).
     */
    public const DEMO_VAT_BASIS_POINTS = 1000;

    /**
     * @var array<string, array<string, mixed>>
     */
    public const DEMO_OFFERS = [
        'Création de site vitrine pour artisans' => [
            'summary' => 'Un site vitrine de cinq pages, responsive, livré en trois semaines avec sa mise en ligne et une formation.',
            'description' => 'Maquettes, intégration, formulaire de contact, référencement de base et formation à la mise à jour des contenus.',
            'category' => 'site-vitrine', 'pricing_mode' => 'from', 'price_cents' => 150_000, 'delivery_mode' => 'remote', 'country' => 'FR', 'city' => 'Lyon',
        ],
        'Refonte d\'identité visuelle' => [
            'summary' => 'Logo, palette de couleurs, typographies et charte graphique, adaptés à votre activité et à vos supports.',
            'description' => 'Atelier de découverte, trois pistes créatives, deux allers-retours, livraison des fichiers et de la charte.',
            'category' => 'identite-visuelle', 'pricing_mode' => 'on_quote', 'price_cents' => null, 'delivery_mode' => 'both', 'country' => 'FR', 'city' => 'Lyon',
        ],
    ];

    public function run(): void
    {
        if (! DemoDataGuard::allowsSeeding()) {
            throw new RuntimeException('Les données de démonstration du module Marketplace sont réservées au poste local (DemoDataGuard).');
        }

        Notification::fake();

        $this->seedDemoVatRate();

        $provider = User::query()->where('email', 'prestataire@example.com')->first();
        $particulier = User::query()->where('email', 'particulier@example.com')->first();

        if (! $provider instanceof User) {
            return;
        }

        $this->seedSellerProfile($provider);
        $this->seedOffers($provider);

        if ($particulier instanceof User) {
            $this->seedQuote($provider, $particulier);
        }
    }

    private function seedDemoVatRate(): void
    {
        if (ComplianceRule::query()->where('code', self::DEMO_VAT_CODE)->where('is_demo', true)->exists()) {
            return;
        }

        $rule = new ComplianceRule;
        $rule->forceFill([
            'uuid' => (string) Str::uuid(),
            'country' => 'FR',
            'kind' => RuleKind::VatRate,
            'code' => self::DEMO_VAT_CODE,
            'valid_from' => now()->subYear()->startOfYear()->toDateString(),
            'valid_until' => null,
            'status' => RuleStatus::Verified,
            'version' => 1,
            'title' => ['fr' => '[Démo] Taux de TVA fictif', 'en' => '[Demo] Fictitious VAT rate'],
            'summary' => null,
            'conditions' => ['legal_forms' => [], 'activity_types' => [], 'vat_regimes' => [], 'options' => []],
            'payload' => [
                'basis_points' => self::DEMO_VAT_BASIS_POINTS,
                'label' => ['fr' => '[Démo] Taux fictif', 'en' => '[Demo] Fictitious rate'],
                'scope' => ['fr' => 'Taux de démonstration, sans valeur fiscale.', 'en' => 'Demonstration rate, no tax value.'],
                'mention' => null,
                'client_certification' => null,
                'exclusive' => false,
            ],
            'source_url' => 'https://www.example.com/regle-fictive-de-demonstration',
            'source_label' => 'Règle fictive de démonstration',
            'last_verified_at' => now(),
            'verification_note' => 'Règle fictive de démonstration (is_demo) : elle ne décrit aucun taux réel.',
            'internal_notes' => 'Données de démonstration (is_demo) : ne jamais copier en production.',
            'is_demo' => true,
        ])->save();
    }

    private function seedSellerProfile(User $provider): void
    {
        if (SellerProfile::query()->where('user_id', $provider->id)->exists()) {
            return;
        }

        $profile = new SellerProfile;
        $profile->forceFill([
            'user_id' => $provider->id,
            'legal_name' => 'Prestataire Test',
            'trade_name' => 'Atelier de démonstration',
            'legal_form' => 'ei_micro',
            'registration_number' => '123456782',
            'registry' => 'rne',
            'address_line1' => '1 rue de la Démonstration',
            'postal_code' => '69001',
            'city' => 'Lyon',
            'country' => 'FR',
            'phone' => '+33 4 00 00 00 00',
            'email' => 'prestataire@example.com',
            'insurer_name' => 'Assurance fictive',
            'insurer_contact' => '1 avenue de l\'Exemple, 69002 Lyon',
            'insurance_coverage' => 'France',
            'mediator_name' => 'Médiateur fictif',
            'mediator_url' => 'https://www.example.com/mediateur',
            'quote_footer' => '[Démo] Mentions personnalisées du prestataire.',
            'self_certified_at' => now(),
            'self_certification_version' => (string) config('marketplace.self_certification.version'),
        ])->save();
    }

    private function seedOffers(User $provider): void
    {
        foreach (self::DEMO_OFFERS as $title => $data) {
            if (Offer::query()->where('user_id', $provider->id)->where('title', $title)->exists()
                || ! ServiceCategory::query()->where('slug', $data['category'])->exists()) {
                continue;
            }

            $offer = app(SaveOffer::class)->create($provider, ['title' => $title, ...$data]);
            app(PublishOffer::class)->handle($provider, $offer);
        }
    }

    private function seedQuote(User $provider, User $particulier): void
    {
        $matchRequest = MatchRequest::query()
            ->where('type', MatchRequestType::Proposal->value)
            ->where('author_id', $provider->id)
            ->where('recipient_id', $particulier->id)
            ->where('status', MatchRequestStatus::Accepted->value)
            ->first();

        if ($matchRequest === null || Quote::query()->where('match_request_id', $matchRequest->id)->exists()) {
            return;
        }

        $line = fn (string $type, string $designation, int $quantity, string $unit, int $price): array => [
            'line_type' => $type, 'designation' => $designation, 'details' => null, 'quantity_milli' => $quantity,
            'unit' => $unit, 'unit_price_cents' => $price, 'vat_rate_code' => self::DEMO_VAT_CODE,
        ];

        $quote = app(SaveQuoteDraft::class)->create($provider, [
            'match_request' => $matchRequest->uuid,
            'title' => 'Site vitrine de l\'atelier',
            'scope' => 'Conception et réalisation du site vitrine de cinq pages, avec mise en ligne et formation à la mise à jour.',
            'client' => ['name' => $particulier->name, 'address' => null, 'execution_place' => 'À distance'],
            'validity_days' => 30,
            'duration' => 'Trois semaines',
            'payment_terms' => 'Paiement à réception de facture.',
            'lines' => [
                $line('labour', 'Maquettes et intégration', 3500, 'day', 40_000),
                $line('service', 'Mise en ligne et formation', 1000, 'lump_sum', 30_000),
            ],
        ]);

        app(SendQuote::class)->handle($provider, $quote);
    }
}
