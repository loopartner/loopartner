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
use App\Modules\Events\Actions\ApproveEvent;
use App\Modules\Events\Actions\CreateEvent;
use App\Modules\Events\Actions\RegisterForEvent;
use App\Modules\Events\Actions\RejectEvent;
use App\Modules\Events\Actions\SubmitEvent;
use App\Modules\Events\Actions\UpdateEvent;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Enums\RegistrationStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Events\Models\EventRegistration;
use App\Modules\Events\Support\EventPublisher;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

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
     * Secret TOTP (base32) de la double authentification du compte admin@example.com, pour tester
     * la modération en local : à saisir dans une application d'authentification (voir CLAUDE.md).
     * Public, comme le mot de passe des comptes de démonstration : DemoDataGuard empêche de le
     * créer ailleurs qu'en local, et une base de démonstration ne doit jamais être copiée vers un
     * autre environnement.
     */
    public const DEMO_ADMIN_TWO_FACTOR_SECRET = 'LOOPARTNERDEMOAD';

    /**
     * Codes de secours de la double authentification de admin@example.com.
     *
     * @var list<string>
     */
    public const DEMO_ADMIN_RECOVERY_CODES = ['demo-secours-0001', 'demo-secours-0002', 'demo-secours-0003', 'demo-secours-0004'];

    /**
     * Motif du refus de l'événement de démonstration refusé.
     */
    public const DEMO_REJECTION_REASON = 'Le titre promet des gains irréalistes : décrivez plutôt le contenu concret de l\'atelier.';

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
            $this->seedDemoAdminTwoFactor();
            $this->seedDemoEvents();
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
     * Active et confirme la double authentification du compte admin@example.com avec un secret
     * fixe et documenté (DEMO_ADMIN_TWO_FACTOR_SECRET), sans écraser une 2FA déjà configurée.
     */
    private function seedDemoAdminTwoFactor(): void
    {
        $admin = User::query()->where('email', 'admin@example.com')->sole();

        if ($admin->two_factor_confirmed_at !== null) {
            return;
        }

        $admin->forceFill([
            'two_factor_secret' => encrypt(self::DEMO_ADMIN_TWO_FACTOR_SECRET),
            'two_factor_recovery_codes' => encrypt((string) json_encode(self::DEMO_ADMIN_RECOVERY_CODES)),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    /**
     * Événements de démonstration, créés par les Actions du module Events (mêmes règles qu'en
     * production), sans aucun email :
     * - publiés : un atelier en présentiel (prestataire), un webinaire en ligne (entrepreneur),
     *   une conférence hybride avec une révision en attente (rédacteur) et un salon payant
     *   publié directement par l'admin ;
     * - un événement en attente de validation, un refusé et un passé ;
     * - des inscriptions entre les comptes de démonstration.
     *
     * Chaque événement est repéré par son organisateur et son titre : le seeder peut être relancé.
     */
    private function seedDemoEvents(): void
    {
        Notification::fake();

        $users = User::query()
            ->whereIn('email', array_map(fn (string $role): string => "{$role}@example.com", RoleSeeder::ROLES))
            ->get()
            ->keyBy(fn (User $user): string => (string) strstr($user->email, '@', true));
        $admin = $users['admin'];
        $events = [];

        foreach ($this->demoEvents() as $key => ['organizer' => $role, 'state' => $state, 'data' => $data]) {
            $organizer = $users[$role];
            $existing = Event::query()->where('user_id', $organizer->id)->where('title', $data['title'])->first();

            $events[$key] = $existing ?? $this->createDemoEvent($organizer, $admin, $state, $data);
        }

        // Inscriptions entre les comptes de démonstration (événements gratuits à venir).
        foreach (['atelier' => ['particulier', 'entrepreneur'], 'webinaire' => ['particulier', 'prestataire'], 'conference' => ['entrepreneur']] as $key => $roles) {
            foreach ($roles as $role) {
                if ($events[$key]->isUpcoming() && $events[$key]->registrationOf($users[$role]) === null) {
                    app(RegisterForEvent::class)->handle($users[$role], $events[$key]);
                }
            }
        }

        // Inscription à l'événement passé (créée directement : les inscriptions sont closes).
        $past = $events['passe'];
        if ($past->registrationOf($users['particulier']) === null) {
            (new EventRegistration)->forceFill([
                'uuid' => (string) Str::uuid(),
                'event_id' => $past->id,
                'user_id' => $users['particulier']->id,
                'status' => RegistrationStatus::Registered,
            ])->save();
            $past->forceFill(['registrations_count' => $past->registrations_count + 1])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createDemoEvent(User $organizer, User $admin, string $state, array $data): Event
    {
        if ($state === 'past') {
            return $this->createPastDemoEvent($organizer, $data);
        }

        $event = app(CreateEvent::class)->handle($organizer, $data);

        if ($state === 'direct') {
            return app(SubmitEvent::class)->handle($organizer, $event, publishDirectly: true);
        }

        $event = app(SubmitEvent::class)->handle($organizer, $event, publishDirectly: false);

        return match ($state) {
            'pending' => $event,
            'rejected' => app(RejectEvent::class)->handle($admin, $event, $event->version, self::DEMO_REJECTION_REASON),
            'revision' => $this->withDemoRevision($organizer, app(ApproveEvent::class)->handle($admin, $event, $event->version)),
            default => app(ApproveEvent::class)->handle($admin, $event, $event->version),
        };
    }

    /**
     * Révision en attente : nouveau lieu et nouveau lien de connexion, à valider par l'admin.
     */
    private function withDemoRevision(User $organizer, Event $event): Event
    {
        app(UpdateEvent::class)->handle($organizer, $event, [
            'venue_name' => 'Maison des entrepreneurs',
            'online_url' => 'https://visio.example.com/conference-financement-v2',
        ]);

        return $event->refresh();
    }

    /**
     * Événement passé : les dates passées sont refusées par les Actions, il est donc créé
     * directement, déjà publié puis terminé.
     *
     * @param  array<string, mixed>  $data
     */
    private function createPastDemoEvent(User $organizer, array $data): Event
    {
        $start = now()->subDays(10)->setTime(17, 0);

        $event = new Event(collect($data)->except(['starts_at', 'ends_at', 'categories'])->all());
        $event->forceFill([
            'uuid' => (string) Str::uuid(),
            'user_id' => $organizer->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(2),
            'submitted_at' => $start->copy()->subDays(20),
        ]);
        EventPublisher::publish($event);
        $event->forceFill(['status' => EventStatus::Past, 'published_at' => $start->copy()->subDays(15)])->save();

        return $event;
    }

    /**
     * Événements de démonstration, dates relatives à aujourd'hui (heure de Paris).
     *
     * @return array<string, array{organizer: string, state: string, data: array<string, mixed>}>
     */
    private function demoEvents(): array
    {
        $at = fn (int $days, string $time): string => now('Europe/Paris')->addDays($days)->format('Y-m-d').'T'.$time;
        $common = ['language' => 'fr', 'timezone' => 'Europe/Paris'];

        return [
            'atelier' => ['organizer' => 'prestataire', 'state' => 'published', 'data' => [...$common,
                'title' => 'Atelier : bien démarrer sa comptabilité',
                'description' => 'Deux heures pour mettre en place la tenue de vos comptes : factures, notes de frais, TVA et outils simples. Questions-réponses en fin d\'atelier.',
                'type' => 'workshop', 'format' => 'in_person', 'categories' => ['tenue-comptable'],
                'venue_name' => 'La Cordée Liberté', 'address_line' => '12 rue de la République', 'postal_code' => '69002', 'city' => 'Lyon', 'country' => 'FR',
                'starts_at' => $at(10, '18:30'), 'ends_at' => $at(10, '20:30'), 'capacity' => 30,
            ]],
            'webinaire' => ['organizer' => 'entrepreneur', 'state' => 'published', 'data' => [...$common,
                'title' => 'Webinaire : protéger sa marque',
                'description' => 'Dépôt de marque, recherche d\'antériorité et erreurs fréquentes : un webinaire d\'une heure pour les créateurs d\'entreprise.',
                'type' => 'webinar', 'format' => 'online', 'organizer_name' => 'Club des créateurs',
                'online_url' => 'https://visio.example.com/webinaire-marque',
                'starts_at' => $at(14, '12:30'), 'ends_at' => $at(14, '13:30'),
            ]],
            'conference' => ['organizer' => 'redacteur', 'state' => 'revision', 'data' => [...$common,
                'title' => 'Conférence : financer sa création d\'entreprise',
                'description' => 'Prêt d\'honneur, BPI, love money et banques : trois intervenants présentent les solutions de financement pour démarrer.',
                'type' => 'conference', 'format' => 'hybrid', 'organizer_name' => 'Rédaction Loopartner', 'categories' => ['business-plan'],
                'venue_name' => 'Espace Bastille', 'address_line' => '5 rue de la Roquette', 'postal_code' => '75011', 'city' => 'Paris', 'country' => 'FR',
                'address_visibility' => 'registrants',
                'online_url' => 'https://visio.example.com/conference-financement',
                'starts_at' => $at(21, '09:30'), 'ends_at' => $at(21, '12:30'), 'capacity' => 120,
            ]],
            'salon' => ['organizer' => 'admin', 'state' => 'direct', 'data' => [...$common,
                'title' => 'Salon des jeunes entreprises',
                'description' => 'Deux jours de rencontres avec des experts, des financeurs et des entrepreneurs, conférences et ateliers compris.',
                'type' => 'trade_show', 'format' => 'in_person', 'organizer_name' => 'Loopartner',
                'venue_name' => 'Parc des expositions', 'address_line' => 'Cours Charles Bricaud', 'postal_code' => '33300', 'city' => 'Bordeaux', 'country' => 'FR',
                'is_free' => false, 'ticket_url' => 'https://billetterie.example.com/salon-jeunes-entreprises',
                'starts_at' => $at(30, '09:00'), 'ends_at' => $at(31, '18:00'),
            ]],
            'networking' => ['organizer' => 'entrepreneur', 'state' => 'pending', 'data' => [...$common,
                'title' => 'Networking : entrepreneurs de Lille',
                'description' => 'Une soirée pour rencontrer d\'autres entrepreneurs lillois, échanger sur vos projets et trouver des partenaires.',
                'type' => 'networking', 'format' => 'in_person', 'organizer_name' => 'Club des créateurs',
                'venue_name' => 'Euratechnologies', 'address_line' => '165 avenue de Bretagne', 'postal_code' => '59000', 'city' => 'Lille', 'country' => 'FR',
                'starts_at' => $at(40, '19:00'), 'ends_at' => $at(40, '22:00'),
            ]],
            'refuse' => ['organizer' => 'entrepreneur', 'state' => 'rejected', 'data' => [...$common,
                'title' => 'Atelier : devenir riche en une semaine',
                'description' => 'Découvrez la méthode secrète pour multiplier votre chiffre d\'affaires en une semaine, sans effort.',
                'type' => 'workshop', 'format' => 'online', 'organizer_name' => 'Club des créateurs',
                'online_url' => 'https://visio.example.com/atelier-riche',
                'starts_at' => $at(25, '18:00'), 'ends_at' => $at(25, '19:00'),
            ]],
            'passe' => ['organizer' => 'prestataire', 'state' => 'past', 'data' => [...$common,
                'title' => 'Atelier : choisir son statut juridique',
                'description' => 'Micro-entreprise, EURL, SASU : comparer les statuts selon votre activité et vos revenus.',
                'type' => 'workshop', 'format' => 'in_person',
                'venue_name' => 'La Cordée Liberté', 'address_line' => '12 rue de la République', 'postal_code' => '69002', 'city' => 'Lyon', 'country' => 'FR',
            ]],
        ];
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
