<?php

namespace App\Modules\Accounts\Http\Requests;

use App\Http\Requests\AcceptLegalDocumentsRequest;
use App\Modules\Accounts\Enums\AccountType;
use App\Modules\Accounts\Support\PreferenceRules;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Support\Legal\LegalDocuments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * POST /auth/register.
 *
 * L'unicité de l'email n'est volontairement pas validée ici : une erreur « adresse déjà
 * utilisée » révélerait l'existence du compte (voir RegisterAccount).
 *
 * legal_documents ({"terms": "<version>", "privacy": "<version>"}) : acceptation des versions
 * en vigueur des CGU et de la politique de confidentialité, obligatoire (422 si une version
 * est absente ou périmée). Sans aucune version en vigueur, l'inscription est impossible (503).
 */
class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! LegalDocuments::allAvailable()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Les inscriptions sont momentanément fermées. Réessayez plus tard.',
                'code' => 'legal_documents_unavailable',
            ], 503));
        }

        $input = PreferenceRules::normalize($this->all());

        if (isset($input['email']) && is_string($input['email'])) {
            $input['email'] = Str::lower(trim($input['email']));
        }

        $this->replace($input);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255', new NoHtml, new NoInvisibleCharacters],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            // Seuls particulier, prestataire et entrepreneur sont acceptés : jamais redacteur ni admin.
            'account_type' => ['required', 'string', Rule::enum(AccountType::class)],
            ...PreferenceRules::rules(),
            ...AcceptLegalDocumentsRequest::versionRules('legal_documents'),
        ];
    }
}
