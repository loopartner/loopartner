<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Accounts\Actions\FindPublishedProvider;
use App\Modules\Accounts\Actions\UpsertProviderProfile;
use App\Modules\Directory\Actions\AcceptMatchRequest;
use App\Modules\Directory\Actions\CreateProject;
use App\Modules\Directory\Actions\DeclineMatchRequest;
use App\Modules\Directory\Actions\PublishProject;
use App\Modules\Directory\Actions\SendContactRequest;
use App\Modules\Directory\Actions\SendMessage;
use App\Modules\Directory\Actions\SendProposal;
use App\Modules\Directory\Enums\MatchRequestStatus;
use App\Modules\Directory\Enums\MatchRequestType;
use App\Modules\Directory\Models\Conversation;
use App\Modules\Directory\Models\MatchRequest;
use App\Modules\Directory\Models\Project;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

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
     * Messages de la conversation de démonstration (proposition du prestataire acceptée par le
     * particulier), dans l'ordre d'envoi.
     *
     * @var list<array{from: string, body: string}>
     */
    public const DEMO_MESSAGES = [
        ['from' => 'particulier', 'body' => 'Bonjour, merci pour votre proposition ! Pouvez-vous me montrer quelques sites que vous avez réalisés ?'],
        ['from' => 'prestataire', 'body' => 'Bonjour, avec plaisir. Je vous prépare une sélection de trois sites d\'artisans, avec les délais de chacun.'],
        ['from' => 'particulier', 'body' => 'Parfait. Mon budget reste autour de 2 000 €, maquettes comprises.'],
        ['from' => 'prestataire', 'body' => 'C\'est compatible. Je vous propose un premier échange en visio la semaine prochaine pour cadrer le projet.'],
    ];

    /**
     * Données de référence, puis données de démonstration si DemoDataGuard l'autorise
     * (SEED_DEMO_DATA=true, en local uniquement). Le seeder est idempotent : il peut être relancé.
     *
     * En production, lancer uniquement ReferenceDataSeeder.
     */
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);

        if (DemoDataGuard::allowsSeeding()) {
            $this->seedLocalUsers();
            $this->seedDemoMatching();
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

    /**
     * Mise en relation entre les comptes de démonstration, créée par les Actions du module
     * Directory (mêmes règles qu'en production), sans aucun email :
     * - une proposition du prestataire sur le projet publié du particulier, acceptée, avec une
     *   conversation de quelques messages ;
     * - une proposition du prestataire en attente sur le projet publié de l'entrepreneur ;
     * - un contact de l'entrepreneur vers le prestataire, refusé.
     *
     * Chaque élément n'est créé que s'il manque : le seeder peut être relancé.
     */
    private function seedDemoMatching(): void
    {
        // Données locales : aucun email de notification n'est mis en file d'attente.
        Notification::fake();

        $users = User::query()
            ->whereIn('email', ['particulier@example.com', 'prestataire@example.com', 'entrepreneur@example.com'])
            ->get()
            ->keyBy(fn (User $user): string => strstr($user->email, '@', true));

        [$particulier, $provider, $entrepreneur] = [$users['particulier'], $users['prestataire'], $users['entrepreneur']];

        $accepted = $this->demoProposal($provider, $this->demoProject($particulier), 'Bonjour, je réalise des sites vitrines pour des artisans d\'art. Je peux livrer le vôtre en trois semaines, maquettes comprises.', 1900);

        if ($accepted !== null) {
            $matchRequest = app(AcceptMatchRequest::class)->handle($accepted);

            /** @var Conversation $conversation */
            $conversation = $matchRequest->conversation;

            foreach (self::DEMO_MESSAGES as ['from' => $from, 'body' => $body]) {
                app(SendMessage::class)->handle($from === 'prestataire' ? $provider : $particulier, $conversation->fresh(['participants.user']) ?? $conversation, $body);
            }
        }

        $this->demoProposal($provider, $this->demoProject($entrepreneur), 'Bonjour, expert-comptable pour les jeunes SAS, je peux prendre en charge votre tenue comptable et votre premier bilan.', 1800);

        $declinedExists = MatchRequest::query()
            ->where('type', MatchRequestType::Contact->value)
            ->where('author_id', $entrepreneur->id)
            ->where('recipient_id', $provider->id)
            ->exists();
        $providerProfile = app(FindPublishedProvider::class)->handle('prestataire-test');

        if (! $declinedExists && $providerProfile !== null) {
            $contact = app(SendContactRequest::class)->handle($entrepreneur, $providerProfile['user_id'], null, [
                'message' => 'Bonjour, je cherche quelqu\'un pour refaire l\'identité visuelle de ma société. Êtes-vous disponible ce mois-ci ?',
            ]);
            app(DeclineMatchRequest::class)->handle($contact);
        }
    }

    /**
     * Projet publié de démonstration d'un porteur de projet (le premier de DEMO_PROJECTS).
     */
    private function demoProject(User $owner): Project
    {
        $role = strstr($owner->email, '@', true);

        return Project::query()
            ->where('user_id', $owner->id)
            ->where('title', self::DEMO_PROJECTS[$role][0]['data']['title'])
            ->sole();
    }

    /**
     * Envoie une proposition du prestataire sur le projet, sauf s'il en a déjà fait une (quel que
     * soit son statut). Renvoie la proposition créée, ou null si elle existait.
     */
    private function demoProposal(User $provider, Project $project, string $message, int $price): ?MatchRequest
    {
        $exists = MatchRequest::query()
            ->where('author_id', $provider->id)
            ->where('project_id', $project->id)
            ->whereIn('status', [MatchRequestStatus::Pending->value, MatchRequestStatus::Accepted->value])
            ->exists();

        return $exists ? null : app(SendProposal::class)->handle($provider, $project, [
            'message' => $message,
            'price_amount' => $price,
            'price_currency' => 'EUR',
        ]);
    }
}
