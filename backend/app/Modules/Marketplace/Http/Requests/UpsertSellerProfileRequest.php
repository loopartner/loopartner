<?php

namespace App\Modules\Marketplace\Http\Requests;

use App\Modules\Marketplace\Enums\SellerLegalForm;
use App\Modules\Marketplace\Enums\SellerRegistry;
use App\Modules\Marketplace\Models\SellerProfile;
use App\Modules\Marketplace\Rules\FrenchVatNumber;
use App\Modules\Marketplace\Rules\Siren;
use App\Rules\CountryCode;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PUT /me/seller-profile : représentation complète de l'identité professionnelle.
 *
 * Un champ facultatif absent est remis à null. L'autocertification (texte et version de
 * config/marketplace.php) est acceptée à chaque enregistrement. En France, le SIREN et le
 * numéro de TVA sont contrôlés dans leur forme (clé de Luhn, clé de TVA) ; leur existence sera
 * vérifiée au lot 6. Le HTML et les caractères invisibles sont refusés partout.
 */
class UpsertSellerProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage', SellerProfile::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $text = fn (int $max): array => ['string', 'max:'.$max, new NoHtml, new NoInvisibleCharacters];
        $france = $this->input('country') === 'FR';
        $legalForm = SellerLegalForm::tryFrom((string) $this->input('legal_form'));

        return [
            'legal_name' => ['required', 'min:2', ...$text(160)],
            'trade_name' => ['nullable', ...$text(160)],
            'legal_form' => ['required', 'string', Rule::enum(SellerLegalForm::class)],
            'registration_number' => ['required', ...$text(40), $france ? new Siren : 'regex:/^[A-Za-z0-9 .\-]{4,40}$/'],
            'registry' => ['required', 'string', Rule::enum(SellerRegistry::class)],
            'registry_city' => ['nullable', 'required_if:registry,rcs', ...$text(100)],
            'share_capital_cents' => [$legalForm?->isCompany() ? 'required' : 'nullable', 'integer', 'min:0', 'max:10000000000000'],
            'vat_number' => ['nullable', ...$text(40), $france ? new FrenchVatNumber($this->string('registration_number')->toString()) : 'regex:/^[A-Za-z]{2}[A-Za-z0-9 .\-]{2,20}$/'],
            'address_line1' => ['required', ...$text(200)],
            'address_line2' => ['nullable', ...$text(200)],
            'postal_code' => ['required', ...$text(20), $france ? 'regex:/^\d{5}$/' : 'regex:/^[A-Za-z0-9 \-]{2,12}$/'],
            'city' => ['required', ...$text(100)],
            'country' => ['required', 'string', 'size:2', new CountryCode],
            'phone' => ['required', 'string', 'max:30', 'regex:/^\+?[0-9][0-9 .()\-]{5,28}$/'],
            'email' => ['required', 'string', 'max:191', 'email:rfc,strict', new NoInvisibleCharacters],
            'insurer_name' => ['nullable', ...$text(160)],
            'insurer_contact' => ['nullable', 'required_with:insurer_name', ...$text(300)],
            'insurance_coverage' => ['nullable', 'required_with:insurer_name', ...$text(200)],
            'insurance_ten_year' => ['sometimes', 'boolean'],
            'mediator_name' => ['nullable', 'required_with:mediator_url', ...$text(160)],
            'mediator_url' => ['nullable', 'required_with:mediator_name', 'string', 'max:500', new SafeExternalUrl],
            'regulated_profession_title' => ['nullable', 'required_with:regulated_profession_state,regulated_profession_body', ...$text(160)],
            'regulated_profession_state' => ['nullable', 'required_with:regulated_profession_title', ...$text(100)],
            'regulated_profession_body' => ['nullable', 'required_with:regulated_profession_title', ...$text(200)],
            'terms_url' => ['nullable', 'string', 'max:500', new SafeExternalUrl],
            'quote_footer' => ['nullable', ...$text(1000)],
            'self_certification' => ['required', 'accepted'],
            'self_certification_version' => ['required', 'string', Rule::in([(string) config('marketplace.self_certification.version')])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'self_certification_version.in' => 'Le texte de l\'autocertification a changé : lisez la nouvelle version avant de l\'accepter.',
        ];
    }
}
