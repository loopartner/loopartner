<?php

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Support\RuleCatalog;
use App\Modules\Compliance\Support\RuleContent;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une règle (POST /admin/compliance/rules), toujours en brouillon.
 *
 * - country : pays pris en charge (config/compliance.php).
 * - kind, code : fixés à la création ; le code commence par le code pays en minuscules
 *   (« fr.vat.franchise-services »).
 * - Contenu : RuleContent::rules() selon le type (liste fermée, texte brut, montants entiers).
 * - Champs fixés par la plateforme (statut, version, vérification, auteurs…) : refusés (422).
 *
 * L'accès (admin avec 2FA de session) est vérifié par le middleware EnsureModerator.
 */
class StoreRuleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = RuleKind::tryFrom((string) $this->input('kind')) ?? RuleKind::LegalForm;

        return [
            'country' => ['required', 'string', Rule::in(RuleCatalog::configuredCountries())],
            'kind' => ['required', 'string', Rule::enum(RuleKind::class)],
            'code' => ['required', 'string', 'max:100', 'regex:'.RuleContent::CODE_PATTERN, $this->codeMatchesCountry()],
            ...RuleContent::rules($kind, false),
            ...self::platformFields(),
        ];
    }

    private function codeMatchesCountry(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $prefix = strtolower((string) $this->input('country')).'.';

            if (! is_string($value) || ! str_starts_with($value, $prefix)) {
                $fail('Le code d\'une règle commence par le code du pays en minuscules (« '.$prefix.'… »).');
            }
        };
    }

    /**
     * @param  list<string>  $except
     * @return array<string, list<string>>
     */
    public static function platformFields(array $except = []): array
    {
        return collect([
            'uuid', 'status', 'version', 'is_demo', 'last_verified_at', 'verification_note',
            'verified_by', 'created_by', 'updated_by', 'replaces_rule_id', 'retired_at',
            'retirement_reason', 'id', 'created_at', 'updated_at',
        ])->diff($except)->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])->all();
    }
}
