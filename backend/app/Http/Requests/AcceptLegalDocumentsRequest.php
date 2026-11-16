<?php

namespace App\Http\Requests;

use App\Support\Legal\LegalDocuments;
use App\Support\Legal\LegalDocumentType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /me/legal/accept : {"documents": {"terms": "<version>", "privacy": "<version>"}}.
 *
 * Les versions doivent être exactement celles en vigueur : une version périmée (le document a
 * changé pendant la lecture) donne 422, l'utilisateur relit la nouvelle version.
 */
class AcceptLegalDocumentsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return self::versionRules('documents');
    }

    /**
     * Règles d'un champ {"terms": "<version>", "privacy": "<version>"} (aussi à l'inscription).
     *
     * @return array<string, mixed>
     */
    public static function versionRules(string $field): array
    {
        $rules = [
            $field => ['required', 'array:'.implode(',', LegalDocumentType::values()), self::matchesCurrent()],
        ];

        foreach (LegalDocumentType::values() as $type) {
            $rules["{$field}.{$type}"] = ['required', 'string', 'regex:'.LegalDocuments::VERSION_PATTERN];
        }

        return $rules;
    }

    private static function matchesCurrent(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_array($value) && LegalDocuments::firstMismatch($value) !== null) {
                $fail('Les conditions d\'utilisation ou la politique de confidentialité ont changé : relisez la version en vigueur avant de l\'accepter.');
            }
        };
    }
}
