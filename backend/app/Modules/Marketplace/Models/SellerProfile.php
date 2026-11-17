<?php

namespace App\Modules\Marketplace\Models;

use App\Models\User;
use App\Modules\Marketplace\Enums\SellerLegalForm;
use App\Modules\Marketplace\Enums\SellerRegistry;
use Carbon\CarbonInterface;
use Database\Factories\Modules\Marketplace\SellerProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Identité professionnelle d'un prestataire (décision 37) : mentions obligatoires de ses devis
 * et qualité déclarée affichée sur ses offres.
 *
 * - Chiffrée en base (APP_KEY), sauf la forme juridique, le registre, le capital et le pays.
 * - Jamais publique en entier : l'offre n'affiche que la qualité déclarée, la dénomination et le
 *   SIREN ; le reste n'apparaît que sur les devis, au seul client.
 * - Seuls les champs de contenu sont assignables ; l'autocertification, la numérotation des
 *   devis et la vérification du lot 6 sont fixées par les Actions.
 *
 * @property int $id
 * @property int $user_id
 * @property string $legal_name
 * @property string|null $trade_name
 * @property SellerLegalForm $legal_form
 * @property string $registration_number
 * @property SellerRegistry $registry
 * @property string|null $registry_city
 * @property int|null $share_capital_cents
 * @property string|null $vat_number
 * @property string $address_line1
 * @property string|null $address_line2
 * @property string $postal_code
 * @property string $city
 * @property string $country
 * @property string $phone
 * @property string $email
 * @property string|null $insurer_name
 * @property string|null $insurer_contact
 * @property string|null $insurance_coverage
 * @property bool $insurance_ten_year
 * @property string|null $mediator_name
 * @property string|null $mediator_url
 * @property string|null $regulated_profession_title
 * @property string|null $regulated_profession_state
 * @property string|null $regulated_profession_body
 * @property string|null $terms_url
 * @property string|null $quote_footer
 * @property CarbonInterface|null $self_certified_at
 * @property string|null $self_certification_version
 * @property string|null $registration_verification_status
 * @property string|null $registration_verification_source
 * @property string|null $registration_verified_number_hash
 * @property CarbonInterface|null $registration_verified_at
 * @property CarbonInterface|null $registration_checked_at
 * @property int|null $last_quote_year
 * @property int $last_quote_sequence
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[UseFactory(SellerProfileFactory::class)]
#[Fillable([
    'legal_name', 'trade_name', 'legal_form', 'registration_number', 'registry', 'registry_city',
    'share_capital_cents', 'vat_number', 'address_line1', 'address_line2', 'postal_code', 'city',
    'country', 'phone', 'email', 'insurer_name', 'insurer_contact', 'insurance_coverage',
    'insurance_ten_year', 'mediator_name', 'mediator_url', 'regulated_profession_title',
    'regulated_profession_state', 'regulated_profession_body', 'terms_url', 'quote_footer',
])]
class SellerProfile extends Model
{
    /** @use HasFactory<SellerProfileFactory> */
    use HasFactory;

    protected $table = 'marketplace_seller_profiles';

    /**
     * Champs exigés pour envoyer un devis à un consommateur (médiateur de la consommation,
     * Code de la consommation L616-1 et R616-1), en plus de l'identité complète.
     */
    public const CONSUMER_QUOTE_FIELDS = ['mediator_name', 'mediator_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'legal_name' => 'encrypted',
            'trade_name' => 'encrypted',
            'legal_form' => SellerLegalForm::class,
            'registration_number' => 'encrypted',
            'registry' => SellerRegistry::class,
            'registry_city' => 'encrypted',
            'share_capital_cents' => 'integer',
            'vat_number' => 'encrypted',
            'address_line1' => 'encrypted',
            'address_line2' => 'encrypted',
            'postal_code' => 'encrypted',
            'city' => 'encrypted',
            'phone' => 'encrypted',
            'email' => 'encrypted',
            'insurer_name' => 'encrypted',
            'insurer_contact' => 'encrypted',
            'insurance_coverage' => 'encrypted',
            'insurance_ten_year' => 'boolean',
            'mediator_name' => 'encrypted',
            'mediator_url' => 'encrypted',
            'regulated_profession_title' => 'encrypted',
            'regulated_profession_state' => 'encrypted',
            'regulated_profession_body' => 'encrypted',
            'terms_url' => 'encrypted',
            'quote_footer' => 'encrypted',
            'self_certified_at' => 'datetime',
            'registration_verified_at' => 'datetime',
            'registration_checked_at' => 'datetime',
            'last_quote_year' => 'integer',
            'last_quote_sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Autocertification acceptée dans sa version en vigueur.
     */
    public function hasCurrentSelfCertification(): bool
    {
        return $this->self_certified_at !== null
            && $this->self_certification_version === (string) config('marketplace.self_certification.version');
    }

    /**
     * Établi dans un pays où les devis sont proposés (config/marketplace.php, countries).
     */
    public function isInQuoteCountry(): bool
    {
        return array_key_exists($this->country, (array) config('marketplace.countries'));
    }

    /**
     * Ce qui manque pour publier une offre (liste vide : rien).
     *
     * @return list<string>
     */
    public function missingForOffers(): array
    {
        return $this->hasCurrentSelfCertification() ? [] : ['self_certification'];
    }

    /**
     * Ce qui manque pour envoyer un devis à un professionnel.
     *
     * @return list<string>
     */
    public function missingForQuotes(): array
    {
        return [...$this->missingForOffers(), ...($this->isInQuoteCountry() ? [] : ['country'])];
    }

    /**
     * Ce qui manque pour envoyer un devis à un consommateur.
     *
     * @return list<string>
     */
    public function missingForConsumerQuotes(): array
    {
        $missing = $this->missingForQuotes();

        foreach (self::CONSUMER_QUOTE_FIELDS as $field) {
            if (blank($this->{$field})) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
