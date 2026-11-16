<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptLegalDocumentsRequest;
use App\Models\LegalAcceptance;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use App\Support\Locale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Consentements de l'utilisateur connecté (permis même quand une acceptation est exigée).
 */
class LegalConsentController extends Controller
{
    /**
     * GET /me/legal : versions en vigueur, acceptées ou non, et date d'acceptation.
     */
    public function show(Request $request): JsonResponse
    {
        $user = self::user($request);
        $locale = Locale::fromRequest($request);
        $current = LegalDocuments::current();

        $acceptances = LegalAcceptance::query()
            ->where('user_id', $user->id)
            ->whereIn('legal_document_id', array_map(fn ($document): int => $document->id, $current))
            ->get(['legal_document_id', 'accepted_at'])
            ->keyBy('legal_document_id');

        $documents = [];

        foreach ($current as $document) {
            $acceptance = $acceptances->get($document->id);

            $documents[] = [
                ...LegalDocuments::summary($document, $locale),
                'accepted' => $acceptance !== null,
                'accepted_at' => $acceptance?->accepted_at->toIso8601ZuluString(),
            ];
        }

        return response()->json(['data' => [
            'consent_required' => LegalDocuments::pendingFor($user) !== [],
            'documents' => $documents,
        ]]);
    }

    /**
     * POST /me/legal/accept : accepte les versions en vigueur.
     */
    public function accept(AcceptLegalDocumentsRequest $request): JsonResponse
    {
        /** @var array{documents: array<string, string>} $data */
        $data = $request->validated();

        LegalDocuments::accept(self::user($request), $data['documents'], $request->ip());

        return $this->show($request);
    }

    private static function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
