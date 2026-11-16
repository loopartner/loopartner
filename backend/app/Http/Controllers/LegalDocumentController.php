<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use App\Support\Legal\LegalDocuments;
use App\Support\Legal\LegalDocumentType;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Documents légaux, publics : versions en vigueur (et à venir), texte d'une version.
 */
class LegalDocumentController extends Controller
{
    /**
     * GET /legal/documents : version en vigueur de chaque document, et la prochaine si elle
     * est déjà publiée (information avant l'entrée en vigueur).
     */
    public function index(Request $request): JsonResponse
    {
        $locale = Locale::fromRequest($request);
        $current = LegalDocuments::current();
        $upcoming = LegalDocuments::upcoming();

        $data = [];

        foreach (LegalDocumentType::values() as $type) {
            $data[] = [
                'type' => $type,
                'current' => isset($current[$type]) ? LegalDocuments::summary($current[$type], $locale) : null,
                'upcoming' => isset($upcoming[$type]) ? LegalDocuments::summary($upcoming[$type], $locale) : null,
            ];
        }

        return response()->json(['data' => $data])->header('Vary', 'Accept-Language');
    }

    /**
     * GET /legal/documents/{type}?version= : texte de la version en vigueur, ou d'une version
     * donnée (déjà en vigueur ou publiée à l'avance). Inconnue : 404.
     */
    public function show(Request $request, string $type): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['sometimes', 'string', 'regex:'.LegalDocuments::VERSION_PATTERN],
        ]);

        $locale = Locale::fromRequest($request);

        $document = isset($validated['version'])
            ? LegalDocument::query()->usable()->where('type', $type)->where('version', $validated['version'])->first()
            : (LegalDocuments::current()[$type] ?? null);

        abort_if($document === null, 404, 'Document introuvable.');

        // current() ne charge pas le texte : relecture complète.
        $document = LegalDocument::query()->whereKey($document->id)->firstOrFail();

        return response()->json(['data' => [
            ...LegalDocuments::summary($document, $locale),
            'body' => $document->bodyIn($locale),
            'content_hash' => $document->content_hash,
            'locale' => array_key_exists($locale, $document->body) ? $locale : 'fr',
        ]])->header('Vary', 'Accept-Language');
    }

    /**
     * Contrainte de la route : un type de document connu (sinon 404).
     */
    public static function routeConstraint(): string
    {
        return implode('|', LegalDocumentType::values());
    }
}
