<?php

namespace App\Support\Legal;

use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\User;
use App\Rules\NoHtml;
use App\Rules\NoInvisibleCharacters;
use App\Support\Locale;
use App\Support\SecurityLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Documents légaux versionnés et consentements (noyau, décision 14).
 *
 * - Version en vigueur d'un type : la plus récente dont effective_at est passé (une version
 *   peut être publiée à l'avance : elle est annoncée comme « à venir »).
 * - Tant qu'un utilisateur n'a pas accepté chaque version en vigueur, l'API lui répond 403
 *   « consent_required » (App\Http\Middleware\EnsureLegalConsent), sauf pour lire les
 *   documents, accepter, se déconnecter et supprimer son compte.
 * - Sans aucune version en vigueur (base neuve), rien n'est exigé des comptes, mais
 *   l'inscription est impossible (503) et security:check-config échoue.
 */
final class LegalDocuments
{
    public const VERSION_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/';

    /**
     * Version en vigueur de chaque type (absente s'il n'y en a aucune).
     *
     * @return array<string, LegalDocument>
     */
    public static function current(): array
    {
        $current = [];

        $documents = LegalDocument::query()
            ->usable()
            ->where('effective_at', '<=', now())
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'type', 'version', 'title', 'content_hash', 'effective_at', 'is_demo']);

        foreach ($documents as $document) {
            $current[$document->type->value] ??= $document;
        }

        return self::inTypeOrder($current);
    }

    /**
     * Prochaine version publiée à l'avance, par type.
     *
     * @return array<string, LegalDocument>
     */
    public static function upcoming(): array
    {
        $upcoming = [];

        $documents = LegalDocument::query()
            ->usable()
            ->where('effective_at', '>', now())
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'type', 'version', 'title', 'content_hash', 'effective_at', 'is_demo']);

        foreach ($documents as $document) {
            $upcoming[$document->type->value] ??= $document;
        }

        return self::inTypeOrder($upcoming);
    }

    /**
     * Toujours dans le même ordre : CGU, puis politique de confidentialité.
     *
     * @param  array<string, LegalDocument>  $documents
     * @return array<string, LegalDocument>
     */
    private static function inTypeOrder(array $documents): array
    {
        return array_filter(array_merge(array_fill_keys(LegalDocumentType::values(), null), $documents));
    }

    /**
     * Chaque type de document a-t-il une version en vigueur ? (inscription possible)
     */
    public static function allAvailable(): bool
    {
        return array_diff(LegalDocumentType::values(), array_keys(self::current())) === [];
    }

    /**
     * Versions en vigueur que l'utilisateur n'a pas encore acceptées.
     *
     * @return list<LegalDocument>
     */
    public static function pendingFor(User $user): array
    {
        $current = self::current();

        if ($current === []) {
            return [];
        }

        $accepted = LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->whereIn('legal_document_id', array_map(fn (LegalDocument $document): int => $document->id, $current))
            ->pluck('legal_document_id')
            ->all();

        return array_values(array_filter($current, fn (LegalDocument $document): bool => ! in_array($document->id, $accepted, true)));
    }

    /**
     * Enregistre l'acceptation des versions en vigueur données (type => version), déjà
     * contrôlées par l'appelant. Une version déjà acceptée n'est pas réenregistrée.
     *
     * @param  array<string, string>  $versions
     */
    public static function accept(User $user, array $versions, ?string $ip): void
    {
        $current = self::current();

        DB::transaction(function () use ($user, $versions, $ip, $current): void {
            foreach ($versions as $type => $version) {
                $document = $current[$type] ?? null;

                if ($document === null || $document->version !== $version) {
                    continue;
                }

                $exists = LegalAcceptance::query()
                    ->where('user_id', $user->id)
                    ->where('legal_document_id', $document->id)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $acceptance = new LegalAcceptance;
                $acceptance->forceFill([
                    'user_id' => $user->id,
                    'legal_document_id' => $document->id,
                    'accepted_at' => now(),
                    'ip_address' => $ip,
                ])->save();

                SecurityLog::record('legal.accepted', [
                    'user_id' => $user->id,
                    'document' => $type,
                    'version' => $version,
                ]);
            }
        });
    }

    /**
     * Les versions envoyées par le client sont-elles exactement les versions en vigueur ?
     * Renvoie le type de la première version périmée ou absente, sinon null.
     *
     * @param  array<array-key, mixed>  $versions
     */
    public static function firstMismatch(array $versions): ?string
    {
        foreach (self::current() as $type => $document) {
            if (($versions[$type] ?? null) !== $document->version) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Réponse 403 « consent_required » : documents à accepter, sans leur texte.
     *
     * @param  list<LegalDocument>  $pending
     * @return array{message: string, code: string, documents: list<array{type: string, version: string, title: string, effective_at: string}>}
     */
    public static function consentRequiredPayload(array $pending, string $locale): array
    {
        return [
            'message' => 'Une nouvelle version des conditions d\'utilisation ou de la politique de confidentialité est en vigueur : acceptez-la pour continuer.',
            'code' => 'consent_required',
            'documents' => array_map(fn (LegalDocument $document): array => self::summary($document, $locale), $pending),
        ];
    }

    /**
     * @return array{type: string, version: string, title: string, effective_at: string}
     */
    public static function summary(LegalDocument $document, string $locale): array
    {
        return [
            'type' => $document->type->value,
            'version' => $document->version,
            'title' => $document->titleIn($locale),
            'effective_at' => $document->effective_at->toIso8601ZuluString(),
        ];
    }

    /**
     * Empreinte du contenu accepté (titre et texte dans chaque langue).
     *
     * @param  array<string, string>  $title
     * @param  array<string, string>  $body
     */
    public static function hash(array $title, array $body): string
    {
        ksort($title);
        ksort($body);

        return hash('sha256', (string) json_encode(['title' => $title, 'body' => $body], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Publie une nouvelle version (commande legal:publish). Une version existante n'est
     * jamais remplacée ; la date d'entrée en vigueur ne peut pas être passée.
     *
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function publish(array $input, bool $demo = false): LegalDocument
    {
        /** @var array{type: string, version: string, effective_at?: string|null, title: array<string, string>, body: array<string, string>} $data */
        $data = Validator::make($input, self::publicationRules($input))->validate();

        $effectiveAt = isset($data['effective_at'])
            ? CarbonImmutable::parse($data['effective_at'])->utc()
            : CarbonImmutable::now('UTC');

        if ($effectiveAt->lt(now()->subMinutes(5))) {
            throw ValidationException::withMessages(['effective_at' => 'Une version ne peut pas entrer en vigueur dans le passé.']);
        }

        $title = array_intersect_key($data['title'], array_flip(Locale::SUPPORTED));
        $body = array_intersect_key($data['body'], array_flip(Locale::SUPPORTED));

        $document = new LegalDocument;
        $document->forceFill([
            'uuid' => (string) Str::uuid(),
            'type' => $data['type'],
            'version' => $data['version'],
            'title' => $title,
            'body' => $body,
            'content_hash' => self::hash($title, $body),
            'effective_at' => Carbon::instance($effectiveAt),
            'is_demo' => $demo,
        ])->save();

        SecurityLog::record('legal.document_published', [
            'document' => $document->type->value,
            'version' => $document->version,
            'effective_at' => $document->effective_at->toIso8601ZuluString(),
            'demo' => $demo,
        ]);

        return $document;
    }

    /**
     * @param  array<array-key, mixed>  $input
     * @return array<string, mixed>
     */
    private static function publicationRules(array $input): array
    {
        $rules = [
            'type' => ['required', 'string', Rule::in(LegalDocumentType::values())],
            // Une version publiée n'est jamais remplacée (index unique en base aussi).
            'version' => [
                'required', 'string', 'regex:'.self::VERSION_PATTERN,
                Rule::unique('legal_documents', 'version')->where('type', is_string($input['type'] ?? null) ? $input['type'] : ''),
            ],
            'effective_at' => ['nullable', 'date'],
            'title' => ['required', 'array:'.implode(',', Locale::SUPPORTED)],
            'body' => ['required', 'array:'.implode(',', Locale::SUPPORTED)],
        ];

        foreach (Locale::SUPPORTED as $locale) {
            $rules["title.{$locale}"] = ['required', 'string', 'min:3', 'max:200', new NoHtml, new NoInvisibleCharacters];
            $rules["body.{$locale}"] = ['required', 'string', 'min:20', 'max:200000', new NoHtml, new NoInvisibleCharacters];
        }

        return $rules;
    }
}
