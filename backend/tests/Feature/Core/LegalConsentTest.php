<?php

use App\Http\Middleware\EnsureLegalConsent;
use App\Models\LegalAcceptance;
use App\Models\LegalDocument;
use App\Models\User;
use App\Support\Legal\LegalDocuments;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
| CGU et politique de confidentialité versionnées (décision 14) : inscription, blocage
| « consent_required », acceptation, publication par legal:publish.
*/

/**
 * Publie une version de chaque document, en vigueur depuis hier.
 *
 * @return array{terms: LegalDocument, privacy: LegalDocument}
 */
function publishedLegalDocuments(string $suffix = '1'): array
{
    return [
        'terms' => LegalDocument::factory()->create(['version' => "cgu-{$suffix}", 'effective_at' => now()->subDay()]),
        'privacy' => LegalDocument::factory()->privacy()->create(['version' => "confidentialite-{$suffix}", 'effective_at' => now()->subDay()]),
    ];
}

function legalDocumentFile(array $overrides = []): string
{
    $path = tempnam(sys_get_temp_dir(), 'legal');
    file_put_contents($path, json_encode([
        'type' => 'terms',
        'version' => '2027-01',
        'title' => ['fr' => 'Conditions générales d\'utilisation', 'en' => 'Terms of use'],
        'body' => ['fr' => 'Article 1. Objet du service, texte complet des conditions.', 'en' => 'Article 1. Purpose of the service, full text of the terms.'],
        ...$overrides,
    ]));

    return $path;
}

describe('lecture publique', function (): void {
    it('annonce les versions en vigueur et à venir, sans le texte', function (): void {
        publishedLegalDocuments();
        LegalDocument::factory()->create(['version' => 'cgu-2', 'effective_at' => now()->addDays(30)]);

        $response = $this->getJson('/api/v1/legal/documents')->assertOk()->assertHeader('Vary', 'Accept-Language');

        $response->assertJsonPath('data.0.type', 'terms')
            ->assertJsonPath('data.0.current.version', 'cgu-1')
            ->assertJsonPath('data.0.upcoming.version', 'cgu-2')
            ->assertJsonPath('data.1.current.version', 'confidentialite-1')
            ->assertJsonPath('data.1.upcoming', null);
        expect($response->getContent())->not->toContain('Texte de test');
    });

    it('donne le texte de la version en vigueur, ou d\'une version donnée', function (): void {
        publishedLegalDocuments();

        $this->getJson('/api/v1/legal/documents/terms?locale=en')
            ->assertOk()
            ->assertJsonPath('data.version', 'cgu-1')
            ->assertJsonPath('data.title', 'Terms of use (test)')
            ->assertJsonPath('data.body', 'Test text of the terms, with no legal value.')
            ->assertJsonPath('data.content_hash', fn (string $hash): bool => strlen($hash) === 64);

        $this->getJson('/api/v1/legal/documents/privacy?version=confidentialite-1')->assertOk();
        $this->getJson('/api/v1/legal/documents/privacy?version=inconnue')->assertNotFound();
        $this->getJson('/api/v1/legal/documents/cookies')->assertNotFound();
        $this->getJson('/api/v1/legal/documents/terms?version=<script>')->assertUnprocessable();
    });

    it('ne montre jamais une version de démonstration hors du poste local', function (): void {
        LegalDocument::factory()->create(['version' => 'demo-1', 'is_demo' => true]);

        $this->getJson('/api/v1/legal/documents')->assertJsonPath('data.0.current', null);
        $this->getJson('/api/v1/legal/documents/terms?version=demo-1')->assertNotFound();
    });
});

describe('inscription', function (): void {
    it('est impossible tant qu\'aucune version n\'est en vigueur (503)', function (): void {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Camille Martin',
            'email' => 'camille@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
            'account_type' => 'particulier',
            'legal_documents' => ['terms' => 'x', 'privacy' => 'y'],
        ])
            ->assertStatus(503)
            ->assertJsonPath('code', 'legal_documents_unavailable');

        expect(User::query()->count())->toBe(0);
    });

    it('exige l\'acceptation des versions en vigueur (422)', function (mixed $legal): void {
        publishedLegalDocuments();

        $this->postJson('/api/v1/auth/register', [...registrationPayload(), 'legal_documents' => $legal])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['legal_documents']);

        expect(User::query()->count())->toBe(0);
    })->with([
        'absente' => [null],
        'version périmée' => [['terms' => 'cgu-0', 'privacy' => 'confidentialite-1']],
        'un document manquant' => [['terms' => 'cgu-1']],
        'clé inconnue' => [['terms' => 'cgu-1', 'privacy' => 'confidentialite-1', 'cookies' => 'v1']],
    ]);

    it('enregistre l\'acceptation datée et versionnée de chaque document', function (): void {
        Notification::fake();
        $documents = publishedLegalDocuments();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson('/api/v1/auth/register', registrationPayload())
            ->assertStatus(202);

        $user = User::query()->where('email', 'camille@example.com')->sole();
        $acceptances = LegalAcceptance::query()->where('user_id', $user->id)->orderBy('legal_document_id')->get();

        expect($acceptances->pluck('legal_document_id')->all())->toBe([$documents['terms']->id, $documents['privacy']->id])
            ->and($acceptances->first()->accepted_at)->not->toBeNull()
            ->and($acceptances->first()->ip_address)->toBe('203.0.113.7')
            ->and(LegalDocuments::pendingFor($user))->toBe([]);
    });

    it('ne crée aucune acceptation pour une adresse déjà utilisée (réponse identique)', function (): void {
        Notification::fake();
        publishedLegalDocuments();
        $existing = User::factory()->create(['email' => 'camille@example.com']);

        $this->postJson('/api/v1/auth/register', registrationPayload())->assertStatus(202);

        expect(LegalAcceptance::query()->where('user_id', $existing->id)->count())->toBe(0);
    });
});

describe('nouvelle version en vigueur', function (): void {
    it('bloque toute action authentifiée jusqu\'à l\'acceptation (403 consent_required)', function (): void {
        publishedLegalDocuments();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'consent_required')
            ->assertJsonPath('documents.0.type', 'terms')
            ->assertJsonPath('documents.0.version', 'cgu-1')
            ->assertJsonPath('documents.1.type', 'privacy');
        $this->actingAs($user)->getJson('/api/v1/me/notification-preferences')->assertForbidden();
        $this->actingAs($user)->getJson('/api/v1/me/projects')->assertForbidden();
    });

    it('laisse lire les documents, voir et enregistrer son acceptation, puis débloque', function (): void {
        publishedLegalDocuments();
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/legal/documents/terms')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/me/legal')
            ->assertOk()
            ->assertJsonPath('data.consent_required', true)
            ->assertJsonPath('data.documents.0.accepted', false);

        $this->actingAs($user)->postJson('/api/v1/me/legal/accept', ['documents' => ['terms' => 'cgu-1', 'privacy' => 'confidentialite-1']])
            ->assertOk()
            ->assertJsonPath('data.consent_required', false)
            ->assertJsonPath('data.documents.0.accepted', true)
            ->assertJsonPath('data.documents.0.accepted_at', fn (string $date): bool => str_ends_with($date, 'Z'));

        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();
        expect(securityLog('legal.accepted'))->toHaveCount(2);
    });

    it('laisse supprimer son compte, avec ses acceptations', function (): void {
        $documents = publishedLegalDocuments();
        $user = User::factory()->create();
        LegalDocuments::accept($user, ['terms' => 'cgu-1'], null);

        $this->actingAs($user)->deleteJson('/api/v1/me', ['password' => 'password'])->assertNoContent();

        expect(User::query()->count())->toBe(0)
            ->and(LegalAcceptance::query()->count())->toBe(0)
            ->and($documents['terms']->fresh())->not->toBeNull();
    });

    it('ne bloque pas les pages publiques', function (): void {
        publishedLegalDocuments();

        $this->actingAs(User::factory()->create())->getJson('/api/v1/categories')->assertOk();
    });

    it('bloque de nouveau à l\'entrée en vigueur d\'une version publiée à l\'avance, pas avant', function (): void {
        publishedLegalDocuments();
        $user = User::factory()->create();
        LegalDocuments::accept($user, ['terms' => 'cgu-1', 'privacy' => 'confidentialite-1'], null);
        LegalDocument::factory()->create(['version' => 'cgu-2', 'effective_at' => now()->addDays(30)]);

        $this->actingAs($user)->getJson('/api/v1/me')->assertOk();

        $this->travel(31)->days();

        $this->actingAs($user)->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonCount(1, 'documents')
            ->assertJsonPath('documents.0.version', 'cgu-2');
    });

    it('n\'exige rien tant qu\'aucune version n\'est en vigueur', function (): void {
        $this->actingAs(User::factory()->create())->getJson('/api/v1/me')->assertOk();
    });

    it('refuse une acceptation invalide ou périmée (422), sans rien enregistrer', function (mixed $documents): void {
        publishedLegalDocuments();
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/me/legal/accept', ['documents' => $documents])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['documents']);

        expect(LegalAcceptance::query()->count())->toBe(0);
    })->with([
        'version périmée' => [['terms' => 'cgu-0', 'privacy' => 'confidentialite-1']],
        'document manquant' => [['privacy' => 'confidentialite-1']],
        'texte' => ['tout'],
        'clé inconnue' => [['terms' => 'cgu-1', 'privacy' => 'confidentialite-1', 'user_id' => 1]],
    ]);

    it('refuse un visiteur anonyme (401)', function (): void {
        $this->getJson('/api/v1/me/legal')->assertUnauthorized();
        $this->postJson('/api/v1/me/legal/accept', [])->assertUnauthorized();
    });

    it('limite les acceptations (429)', function (): void {
        config(['security.rate_limits.legal_acceptances.per_hour' => 2]);
        publishedLegalDocuments();
        $user = User::factory()->create();

        foreach (range(1, 2) as $attempt) {
            $this->actingAs($user)->postJson('/api/v1/me/legal/accept', ['documents' => ['terms' => 'cgu-1', 'privacy' => 'confidentialite-1']])->assertOk();
        }

        $this->actingAs($user)->postJson('/api/v1/me/legal/accept', ['documents' => ['terms' => 'cgu-1', 'privacy' => 'confidentialite-1']])->assertTooManyRequests();
    });

    it('ne laisse passer que des routes existantes sans acceptation', function (): void {
        foreach (EnsureLegalConsent::ALLOWED_ROUTES as $name) {
            expect(Route::has($name))->toBeTrue($name);
        }
    });

    it('applique le blocage à toutes les routes du groupe api (noyau et modules)', function (): void {
        expect(app('router')->getMiddlewareGroups()['api'])->toContain(EnsureLegalConsent::class);

        // Hors du groupe api : uniquement Fortify (connexion, déconnexion, mot de passe, 2FA).
        $outside = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/') && ! in_array('api', $route->gatherMiddleware(), true))
            ->reject(fn ($route): bool => str_starts_with($route->uri(), 'api/v1/auth/'))
            ->map(fn ($route): string => $route->uri())
            ->values()
            ->all();

        expect($outside)->toBe([]);
    });
});

describe('legal:publish', function (): void {
    it('publie une version immuable avec son empreinte', function (): void {
        $this->artisan('legal:publish', ['file' => legalDocumentFile(['effective_at' => now()->addDays(30)->toIso8601ZuluString()])])
            ->assertSuccessful();

        $document = LegalDocument::query()->sole();
        expect($document->version)->toBe('2027-01')
            ->and($document->is_demo)->toBeFalse()
            ->and($document->content_hash)->toBe(LegalDocuments::hash($document->title, $document->body))
            ->and(LegalDocuments::upcoming())->toHaveKey('terms')
            ->and(securityLog('legal.document_published'))->toHaveCount(1);

        $this->artisan('legal:publish', ['file' => legalDocumentFile()])->assertFailed();
        expect(LegalDocument::query()->count())->toBe(1);
    });

    it('refuse un document invalide', function (array $overrides): void {
        $this->artisan('legal:publish', ['file' => legalDocumentFile($overrides)])->assertFailed();

        expect(LegalDocument::query()->count())->toBe(0);
    })->with([
        'HTML' => [['body' => ['fr' => '<p>Article 1. Objet du service, texte complet.</p>', 'en' => 'Article 1. Purpose of the service, full text.']]],
        'traduction manquante' => [['title' => ['fr' => 'Conditions générales']]],
        'type inconnu' => [['type' => 'cookies']],
        'version invalide' => [['version' => '../2027']],
        'date passée' => [['effective_at' => '2020-01-01T00:00:00Z']],
    ]);

    it('refuse un fichier absent', function (): void {
        $this->artisan('legal:publish', ['file' => '/chemin/inexistant.json'])->assertFailed();
    });
});

describe('données de démonstration', function (): void {
    it('publie une version de démonstration et la fait accepter à chaque compte de démonstration', function (): void {
        allowDemoSeeding();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        expect(LegalDocument::query()->where('is_demo', true)->count())->toBe(2);

        foreach (RoleSeeder::ROLES as $role) {
            $user = User::query()->where('email', "{$role}@example.com")->sole();

            expect(LegalDocuments::pendingFor($user))->toBe([], $role);
        }

        $this->actingAs(User::query()->where('email', 'particulier@example.com')->sole())->getJson('/api/v1/me')->assertOk();
    });
});
