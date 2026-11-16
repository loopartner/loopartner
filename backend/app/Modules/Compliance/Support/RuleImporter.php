<?php

namespace App\Modules\Compliance\Support;

use App\Modules\Compliance\Enums\RuleKind;
use App\Modules\Compliance\Enums\RuleStatus;
use App\Modules\Compliance\Models\ComplianceRule;
use App\Support\SecurityLog;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Import d'un fichier de règles (format « loopartner.compliance.rules », par exemple
 * database/data/compliance/fr-2027.draft.json) : crée UNIQUEMENT des brouillons, jamais
 * visibles des utilisateurs avant leur vérification dans l'administration.
 *
 * - Chaque règle est validée comme une création par l'API d'administration (RuleContent, code
 *   préfixé par le pays, champs de la plateforme refusés).
 * - Tout ou rien : une seule règle invalide, et rien n'est importé.
 * - Une règle qui existe déjà (même pays, même code, même date de début, quel que soit son
 *   statut) est ignorée : une règle vérifiée n'est jamais modifiée par un import.
 * - Jamais appelé par ReferenceDataSeeder : l'import est un geste volontaire de l'équipe.
 */
final class RuleImporter
{
    public const FORMAT = 'loopartner.compliance.rules';

    /**
     * @return array{created: list<string>, skipped: list<string>}
     *
     * @throws RuntimeException fichier illisible ou invalide (message avec les erreurs)
     */
    public static function import(string $path, bool $dryRun = false): array
    {
        $document = self::read($path);
        $country = $document['country'];
        $rules = $document['rules'];
        $errors = [];

        foreach ($rules as $index => $rule) {
            $validator = Validator::make(is_array($rule) ? $rule : [], self::rules($country, is_array($rule) ? $rule : []));

            foreach ($validator->errors()->all() as $message) {
                $code = is_array($rule) && is_string($rule['code'] ?? null) ? $rule['code'] : '#'.$index;
                $errors[] = "{$code} : {$message}";
            }
        }

        if ($errors !== []) {
            throw new RuntimeException("Fichier invalide, aucune règle importée :\n".implode("\n", $errors));
        }

        $created = [];
        $skipped = [];

        DB::transaction(function () use ($rules, $country, $dryRun, &$created, &$skipped): void {
            foreach ($rules as $rule) {
                /** @var array<string, mixed> $rule */
                $kind = RuleKind::from((string) $rule['kind']);
                $code = (string) $rule['code'];
                $exists = ComplianceRule::query()
                    ->where('country', $country)
                    ->where('code', $code)
                    ->whereDate('valid_from', (string) $rule['valid_from'])
                    ->exists();

                if ($exists) {
                    $skipped[] = $code;

                    continue;
                }

                $created[] = $code;

                if ($dryRun) {
                    continue;
                }

                $model = new ComplianceRule;
                $model->forceFill([
                    ...RuleContent::normalize($kind, $rule),
                    'uuid' => (string) Str::uuid(),
                    'country' => $country,
                    'kind' => $kind,
                    'code' => $code,
                    'status' => RuleStatus::Draft,
                    'version' => 1,
                    'is_demo' => false,
                ])->save();
            }
        });

        if (! $dryRun) {
            SecurityLog::record('compliance.rules_imported', [
                'user_id' => null,
                'country' => $country,
                'file' => basename($path),
                'created' => count($created),
                'skipped' => count($skipped),
            ]);
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * @return array{country: string, rules: list<mixed>}
     *
     * @throws RuntimeException
     */
    private static function read(string $path): array
    {
        if (! str_ends_with($path, '.json') || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Fichier introuvable ou illisible : {$path} (un fichier .json est attendu).");
        }

        $document = json_decode((string) file_get_contents($path), true);

        if (! is_array($document) || ($document['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('Format de fichier inconnu (attendu : "'.self::FORMAT.'").');
        }

        $country = $document['country'] ?? null;

        if (! is_string($country) || ! in_array($country, RuleCatalog::configuredCountries(), true)) {
            throw new RuntimeException('Pays absent ou non pris en charge (config/compliance.php).');
        }

        if (! is_array($document['rules'] ?? null) || ! array_is_list($document['rules']) || $document['rules'] === []) {
            throw new RuntimeException('Aucune règle dans le fichier.');
        }

        return ['country' => $country, 'rules' => $document['rules']];
    }

    /**
     * Mêmes règles que la création par l'API d'administration (StoreRuleRequest).
     *
     * @param  array<string, mixed>  $rule
     * @return array<string, mixed>
     */
    private static function rules(string $country, array $rule): array
    {
        $kind = RuleKind::tryFrom((string) ($rule['kind'] ?? '')) ?? RuleKind::LegalForm;
        $prefix = strtolower($country).'.';

        return [
            'kind' => ['required', 'string', Rule::enum(RuleKind::class)],
            'code' => ['required', 'string', 'max:100', 'regex:'.RuleContent::CODE_PATTERN, function (string $attribute, mixed $value, Closure $fail) use ($prefix): void {
                if (! is_string($value) || ! str_starts_with($value, $prefix)) {
                    $fail("Le code doit commencer par « {$prefix} ».");
                }
            }],
            ...RuleContent::rules($kind, false),
            ...collect(['uuid', 'status', 'version', 'is_demo', 'country', 'last_verified_at', 'verification_note', 'verified_by', 'created_by', 'updated_by', 'replaces_rule_id', 'retired_at', 'retirement_reason'])
                ->mapWithKeys(fn (string $field): array => [$field => ['prohibited']])
                ->all(),
        ];
    }
}
