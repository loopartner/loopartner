<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounts\Actions\UpsertProviderProfile;
use App\Modules\Directory\Actions\CreateProject;
use App\Modules\Directory\Actions\PublishProject;
use App\Modules\Directory\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Mot de passe des comptes de test locaux (le même que celui de UserFactory).
     */
    public const LOCAL_PASSWORD = 'password';

    /**
     * Projets de démonstration par compte local : un publié et un brouillon chacun.
     *
     * @var array<string, list<array{published: bool, data: array{title: string, description: string, category: string, budget_min?: int|null, budget_max?: int|null, country: string, city?: string|null, remote_ok?: bool}}>>
     */
    public const DEMO_PROJECTS = [
        'particulier' => [
            ['published' => true, 'data' => [
                'title' => 'Création de mon site vitrine de céramiste',
                'description' => 'Je lance mon atelier de céramique et je cherche un site simple pour présenter mes créations et mes cours.',
                'category' => 'site-vitrine',
                'budget_min' => 1500,
                'budget_max' => 3000,
                'country' => 'FR',
                'city' => 'Lyon',
                'remote_ok' => true,
            ]],
            ['published' => false, 'data' => [
                'title' => 'Logo pour mon activité de traiteur',
                'description' => 'Je démarre une activité de traiteur à domicile et j\'ai besoin d\'un logo et d\'une petite charte graphique.',
                'category' => 'identite-visuelle',
                'budget_max' => 800,
                'country' => 'FR',
                'city' => 'Villeurbanne',
            ]],
        ],
        'entrepreneur' => [
            ['published' => true, 'data' => [
                'title' => 'Recherche d\'un expert-comptable pour ma SAS',
                'description' => 'SAS créée cette année, deux associés : je cherche un accompagnement pour la tenue comptable et le bilan.',
                'category' => 'tenue-comptable',
                'budget_min' => 1200,
                'budget_max' => 2400,
                'country' => 'FR',
                'city' => 'Paris',
                'remote_ok' => true,
            ]],
            ['published' => false, 'data' => [
                'title' => 'Business plan pour une levée de fonds',
                'description' => 'Je prépare une levée de fonds d\'amorçage et j\'ai besoin d\'aide pour le business plan et le prévisionnel.',
                'category' => 'business-plan',
                'country' => 'FR',
                'city' => 'Bordeaux',
            ]],
        ],
    ];

    /**
     * Seed the application's database. Le seeder est idempotent : il peut être relancé.
     */
    public function run(): void
    {
        $this->call([RoleSeeder::class, ServiceCategorySeeder::class]);

        if (app()->isLocal()) {
            $this->seedLocalUsers();
        }
    }

    /**
     * Un utilisateur vérifié par rôle : particulier@example.com, prestataire@example.com, etc.
     */
    private function seedLocalUsers(): void
    {
        foreach (RoleSeeder::ROLES as $role) {
            $user = User::query()->firstOrCreate(
                ['email' => "{$role}@example.com"],
                [
                    'name' => ucfirst($role).' Test',
                    'password' => Hash::make(self::LOCAL_PASSWORD),
                ],
            );

            if (! $user->hasVerifiedEmail()) {
                $user->markEmailAsVerified();
            }

            $user->syncRoles([$role]);

            if ($role === 'prestataire') {
                // Un profil publié doit avoir au moins une catégorie.
                app(UpsertProviderProfile::class)->handle($user, [
                    'display_name' => 'Prestataire Test',
                    'headline' => 'Profil de démonstration',
                    'bio' => 'Profil prestataire créé par le seeder pour le développement local.',
                    'city' => 'Lyon',
                    'country' => 'FR',
                    'categories' => ['creation-statuts', 'developpement-web', 'identite-visuelle'],
                    'status' => 'published',
                ]);
            }

            $this->seedDemoProjects($user, self::DEMO_PROJECTS[$role] ?? []);
        }
    }

    /**
     * Crée les projets absents (repérés par leur titre) et republie un projet de démonstration
     * publié qui aurait expiré ou été clôturé.
     *
     * @param  list<array{published: bool, data: array{title: string, description: string, category: string, budget_min?: int|null, budget_max?: int|null, country: string, city?: string|null, remote_ok?: bool}}>  $projects
     */
    private function seedDemoProjects(User $user, array $projects): void
    {
        foreach ($projects as ['published' => $published, 'data' => $data]) {
            $project = Project::query()->where('user_id', $user->id)->where('title', $data['title'])->first()
                ?? app(CreateProject::class)->handle($user, $data);

            if ($published && ! $project->isLive()) {
                app(PublishProject::class)->handle($user, $project);
            }
        }
    }
}
