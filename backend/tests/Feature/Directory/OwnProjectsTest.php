<?php

use App\Modules\Directory\Enums\ProjectStatus;
use App\Modules\Directory\Models\Project;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-10 09:00:00');
    serviceCategory('site-vitrine', 'Site vitrine', 'Showcase website');
    serviceCategory('design', 'Design', 'Design');
    serviceCategory('archive', 'Archivée', 'Archived', active: false);
});

describe('autorisation', function (): void {
    it('réserve la création et la liste aux particuliers et aux entrepreneurs', function (string $role): void {
        $user = projectOwner($role);

        $this->actingAs($user)->getJson('/api/v1/me/projects')->assertForbidden();
        $this->actingAs($user)->postJson('/api/v1/me/projects', projectPayload())->assertForbidden();

        expect(Project::query()->count())->toBe(0);
    })->with(['prestataire', 'redacteur', 'admin']);

    it('renvoie 401 sans authentification', function (): void {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/me/projects')->assertUnauthorized();
        $this->postJson('/api/v1/me/projects', projectPayload())->assertUnauthorized();
        $this->getJson("/api/v1/me/projects/{$project->uuid}")->assertUnauthorized();
        $this->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => 'Nouveau titre'])->assertUnauthorized();
        $this->deleteJson("/api/v1/me/projects/{$project->uuid}")->assertUnauthorized();
        $this->postJson("/api/v1/me/projects/{$project->uuid}/publish")->assertUnauthorized();
        $this->postJson("/api/v1/me/projects/{$project->uuid}/close")->assertUnauthorized();
    });

    it('cache le projet d\'un autre utilisateur derrière un 404, même publié', function (string $state): void {
        $project = Project::factory()->{$state}()->create(['title' => 'Projet de quelqu\'un d\'autre']);
        $intruder = projectOwner('particulier');

        $this->actingAs($intruder)->getJson("/api/v1/me/projects/{$project->uuid}")
            ->assertNotFound()->assertJsonPath('message', 'Projet introuvable.');
        $this->actingAs($intruder)->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => 'Piraté !'])->assertNotFound();
        // Même avec des données invalides : l'autorisation passe avant la validation.
        $this->actingAs($intruder)->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => ''])->assertNotFound();
        $this->actingAs($intruder)->deleteJson("/api/v1/me/projects/{$project->uuid}")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/me/projects/{$project->uuid}/publish")->assertNotFound();
        $this->actingAs($intruder)->postJson("/api/v1/me/projects/{$project->uuid}/close")->assertNotFound();

        $this->assertModelExists($project);
        expect($project->fresh()->title)->toBe('Projet de quelqu\'un d\'autre');
    })->with(['draft' => 'draft', 'published' => 'published']);

    it('renvoie 404 pour un uuid inconnu ou mal formé', function (): void {
        $user = projectOwner('particulier');

        $this->actingAs($user)->getJson('/api/v1/me/projects/9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d')
            ->assertNotFound()->assertJsonPath('message', 'Projet introuvable.');
        $this->actingAs($user)->getJson('/api/v1/me/projects/pas-un-uuid')->assertNotFound();
    });
});

describe('POST /me/projects', function (): void {
    it('crée un brouillon, avec EUR par défaut, pour un particulier ou un entrepreneur', function (string $role): void {
        $user = projectOwner($role);

        $response = $this->actingAs($user)
            ->postJson('/api/v1/me/projects', projectPayload(['country' => 'fr']))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Création de mon site vitrine')
            ->assertJsonPath('data.category', ['slug' => 'site-vitrine', 'name' => 'Site vitrine'])
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.currency', 'EUR')
            ->assertJsonPath('data.country', 'FR')
            ->assertJsonPath('data.budget_min', 1500)
            ->assertJsonPath('data.budget_max', 3000)
            ->assertJsonPath('data.remote_ok', true)
            ->assertJsonPath('data.desired_start_date', '2026-12-01')
            ->assertJsonPath('data.published_at', null)
            ->assertJsonPath('data.expires_at', null)
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.user_id');

        $project = Project::query()->sole();
        expect($project->user_id)->toBe($user->id)
            ->and($project->status)->toBe(ProjectStatus::Draft)
            ->and($response->json('data.uuid'))->toBe($project->uuid);
    })->with(['particulier', 'entrepreneur']);

    it('permet de créer un brouillon sans email vérifié', function (): void {
        $this->actingAs(projectOwner('particulier', verified: false))
            ->postJson('/api/v1/me/projects', projectPayload())
            ->assertCreated();
    });

    it('accepte un budget fixe, un budget partiel ou aucun budget', function (array $budget): void {
        $this->actingAs(projectOwner('particulier'))
            ->postJson('/api/v1/me/projects', projectPayload($budget))
            ->assertCreated();
    })->with([
        'fixe' => [['budget_min' => 2000, 'budget_max' => 2000]],
        'minimum seul' => [['budget_min' => 2000, 'budget_max' => null]],
        'maximum seul' => [['budget_min' => null, 'budget_max' => 2000]],
        'aucun' => [['budget_min' => null, 'budget_max' => null]],
    ]);

    it('accepte une autre devise en minuscules', function (): void {
        $this->actingAs(projectOwner('entrepreneur'))
            ->postJson('/api/v1/me/projects', projectPayload(['currency' => 'chf']))
            ->assertCreated()
            ->assertJsonPath('data.currency', 'CHF');
    });

    it('refuse le HTML dans les champs texte', function (string $field, string $value): void {
        $this->actingAs(projectOwner('particulier'))
            ->postJson('/api/v1/me/projects', projectPayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'titre' => ['title', '<b>Mon projet</b> urgent'],
        'description' => ['description', 'Bonjour, voici mon projet <script>alert(1)</script>'],
        'ville' => ['city', '<i>Lyon</i>'],
    ]);

    it('refuse les entrées invalides', function (string $field, mixed $value): void {
        $this->actingAs(projectOwner('particulier'))
            ->postJson('/api/v1/me/projects', projectPayload([$field => $value]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        expect(Project::query()->count())->toBe(0);
    })->with([
        'titre absent' => ['title', null],
        'titre trop court' => ['title', 'Site'],
        'titre trop long' => ['title', str_repeat('a', 151)],
        'description trop courte' => ['description', 'Trop court'],
        'description trop longue' => ['description', str_repeat('a', 5001)],
        'catégorie absente' => ['category', null],
        'catégorie inconnue' => ['category', 'inconnue'],
        'catégorie inactive' => ['category', 'archive'],
        'budget négatif' => ['budget_min', -5],
        'budget décimal' => ['budget_max', 10.5],
        'budget trop élevé' => ['budget_max', 10_000_001],
        'devise inconnue' => ['currency', 'ABC'],
        'devise vide' => ['currency', null],
        'pays absent' => ['country', null],
        'pays inconnu' => ['country', 'XX'],
        'ville trop longue' => ['city', str_repeat('a', 101)],
        'télétravail non booléen' => ['remote_ok', 'peut-être'],
        'date passée' => ['desired_start_date', '2026-11-09'],
        'date mal formée' => ['desired_start_date', '01/12/2026'],
    ]);

    it('accepte une date de démarrage aujourd\'hui', function (): void {
        $this->actingAs(projectOwner('particulier'))
            ->postJson('/api/v1/me/projects', projectPayload(['desired_start_date' => '2026-11-10']))
            ->assertCreated();
    });

    it('refuse un budget minimal supérieur au budget maximal', function (): void {
        $this->actingAs(projectOwner('particulier'))
            ->postJson('/api/v1/me/projects', projectPayload(['budget_min' => 3001, 'budget_max' => 3000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['budget_min' => 'inférieur ou égal']);

        expect(Project::query()->count())->toBe(0);
    });
});

describe('GET /me/projects', function (): void {
    it('liste uniquement ses projets, du plus récent au plus ancien, avec un filtre de statut', function (): void {
        $user = projectOwner('particulier');
        Project::factory()->for($user)->create(['title' => 'Ancien brouillon', 'created_at' => now()->subDays(2)]);
        Project::factory()->for($user)->published()->create(['title' => 'Publié', 'created_at' => now()->subDay()]);
        Project::factory()->for($user)->closed()->create(['title' => 'Clos', 'created_at' => now()]);
        Project::factory()->create(['title' => 'Projet d\'un autre']);

        $titles = fn (string $query = '') => array_column(
            $this->actingAs($user)->getJson('/api/v1/me/projects'.$query)->assertOk()->json('data'),
            'title',
        );

        expect($titles())->toBe(['Clos', 'Publié', 'Ancien brouillon'])
            ->and($titles('?status=published'))->toBe(['Publié'])
            ->and($titles('?status=draft'))->toBe(['Ancien brouillon']);

        $this->actingAs($user)->getJson('/api/v1/me/projects?status=archived')
            ->assertUnprocessable()->assertJsonValidationErrors(['status']);
    });

    it('signale un projet publié mais expiré', function (): void {
        $user = projectOwner('particulier');
        Project::factory()->for($user)->expired()->create();

        $this->actingAs($user)->getJson('/api/v1/me/projects')
            ->assertJsonPath('data.0.status', 'published')
            ->assertJsonPath('data.0.is_expired', true);
    });

    it('pagine par 20 et refuse plus de 50 résultats par page', function (): void {
        $user = projectOwner('entrepreneur');
        Project::factory()->for($user)->count(21)->create();

        $this->actingAs($user)->getJson('/api/v1/me/projects')
            ->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 21);
        $this->actingAs($user)->getJson('/api/v1/me/projects?per_page=51')
            ->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
    });
});

describe('GET, PATCH et DELETE /me/projects/{uuid}', function (): void {
    it('affiche son projet', function (): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->create(['title' => 'Mon projet à moi']);

        $this->actingAs($user)->getJson("/api/v1/me/projects/{$project->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $project->uuid)
            ->assertJsonPath('data.title', 'Mon projet à moi');
    });

    it('modifie partiellement son projet, y compris publié, sans changer ses dates', function (): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->published()->create(['budget_min' => 1000, 'budget_max' => 2000]);

        Carbon::setTestNow('2026-11-12 09:00:00');

        $this->actingAs($user)
            ->patchJson("/api/v1/me/projects/{$project->uuid}", ['title' => 'Titre corrigé', 'category' => 'design', 'remote_ok' => '1'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Titre corrigé')
            ->assertJsonPath('data.category.slug', 'design')
            ->assertJsonPath('data.remote_ok', true)
            ->assertJsonPath('data.budget_min', 1000)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.published_at', '2026-11-10T09:00:00Z')
            ->assertJsonPath('data.expires_at', '2027-01-09T09:00:00Z');
    });

    it('compare les budgets sur les valeurs finales lors d\'une modification partielle', function (): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->create(['budget_min' => 1000, 'budget_max' => 2000]);

        $this->actingAs($user)
            ->patchJson("/api/v1/me/projects/{$project->uuid}", ['budget_min' => 2500])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['budget_min']);

        $this->actingAs($user)
            ->patchJson("/api/v1/me/projects/{$project->uuid}", ['budget_max' => null, 'budget_min' => 2500])
            ->assertOk()
            ->assertJsonPath('data.budget_min', 2500)
            ->assertJsonPath('data.budget_max', null);

        expect($project->fresh()->budget_min)->toBe(2500);
    });

    it('refuse de vider un champ obligatoire et le HTML', function (string $field, mixed $value): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->create();

        $this->actingAs($user)
            ->patchJson("/api/v1/me/projects/{$project->uuid}", [$field => $value])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    })->with([
        'titre vide' => ['title', ''],
        'pays nul' => ['country', null],
        'catégorie nulle' => ['category', null],
        'html' => ['description', 'Une description <a href="https://x.example">avec un lien</a>'],
    ]);

    it('ne permet pas de modifier le statut, l\'uuid ni le propriétaire', function (): void {
        $user = projectOwner('particulier');
        $other = projectOwner('particulier');
        $project = Project::factory()->for($user)->create();
        $uuid = $project->uuid;

        $this->actingAs($user)
            ->patchJson("/api/v1/me/projects/{$uuid}", [
                'status' => 'published',
                'uuid' => '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d',
                'user_id' => $other->id,
                'published_at' => '2026-01-01T00:00:00Z',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.uuid', $uuid);

        $project->refresh();
        expect($project->user_id)->toBe($user->id)->and($project->published_at)->toBeNull();
    });

    it('supprime son projet, quel que soit son statut', function (string $state): void {
        $user = projectOwner('particulier');
        $project = Project::factory()->for($user)->{$state}()->create();

        $this->actingAs($user)->deleteJson("/api/v1/me/projects/{$project->uuid}")->assertNoContent();

        $this->assertModelMissing($project);
    })->with(['draft', 'published', 'closed']);
});

it('supprime les projets avec le compte', function (): void {
    $user = projectOwner('entrepreneur');
    $projects = Project::factory()->for($user)->count(2)->sequence([], ['status' => ProjectStatus::Published])->create();
    $other = Project::factory()->create();

    $this->actingAs($user)
        ->deleteJson('/api/v1/me', ['password' => 'password'])
        ->assertNoContent();

    $this->assertModelMissing($user);
    $projects->each(fn (Project $project) => $this->assertModelMissing($project));
    $this->assertModelExists($other);
});
