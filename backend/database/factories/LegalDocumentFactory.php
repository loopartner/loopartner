<?php

namespace Database\Factories;

use App\Models\LegalDocument;
use App\Support\Legal\LegalDocuments;
use App\Support\Legal\LegalDocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Versions de documents légaux pour les tests (textes fictifs).
 *
 * @extends Factory<LegalDocument>
 */
class LegalDocumentFactory extends Factory
{
    public function definition(): array
    {
        $title = ['fr' => 'Conditions générales d\'utilisation (test)', 'en' => 'Terms of use (test)'];
        $body = ['fr' => 'Texte de test des conditions générales, sans valeur juridique.', 'en' => 'Test text of the terms, with no legal value.'];

        return [
            'uuid' => (string) Str::uuid(),
            'type' => LegalDocumentType::Terms,
            'version' => 'test-'.Str::lower(Str::random(8)),
            'title' => $title,
            'body' => $body,
            'content_hash' => LegalDocuments::hash($title, $body),
            'effective_at' => now()->subDay(),
            'is_demo' => false,
        ];
    }

    public function privacy(): static
    {
        return $this->state(function (): array {
            $title = ['fr' => 'Politique de confidentialité (test)', 'en' => 'Privacy policy (test)'];
            $body = ['fr' => 'Texte de test de la politique de confidentialité.', 'en' => 'Test text of the privacy policy.'];

            return [
                'type' => LegalDocumentType::Privacy,
                'title' => $title,
                'body' => $body,
                'content_hash' => LegalDocuments::hash($title, $body),
            ];
        });
    }
}
