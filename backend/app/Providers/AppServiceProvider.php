<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Ai\AiClient;
use App\Support\Ai\Drivers\DisabledAiClient;
use App\Support\Ai\Drivers\FakeAiClient;
use App\Support\SecurityLog;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerAi();
    }

    /**
     * Pilote d'IA choisi par AI_DRIVER (config/ai.php) : un seul par requête, partagé, pour que
     * les tests lisent les requêtes reçues par le pilote simulé. Valeur inconnue : désactivé.
     */
    private function registerAi(): void
    {
        $this->app->singleton(AiClient::class, fn (): AiClient => match (config('ai.driver')) {
            'fake' => new FakeAiClient,
            default => new DisabledAiClient,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureUrls();
        $this->configurePasswords();
        $this->configureRateLimiting();
        $this->logAuthorizationDenials();
    }

    /**
     * Les URL générées (liens signés envoyés par email…) partent toujours de APP_URL, jamais de
     * l'en-tête Host de la requête : un Host forgé ne peut pas détourner un lien vers un autre domaine.
     */
    private function configureUrls(): void
    {
        $root = rtrim((string) config('app.url'), '/');

        URL::forceRootUrl($root);
        URL::forceScheme(parse_url($root, PHP_URL_SCHEME) === 'http' ? 'http' : 'https');
    }

    /**
     * Journalise chaque refus d'une Policy ou d'un Gate (canal security) : accès à la ressource
     * d'un autre utilisateur (souvent 404, voir denyAsNotFound) ou à une fonction réservée à un
     * autre rôle (403). Le résultat de l'autorisation n'est pas modifié.
     */
    private function logAuthorizationDenials(): void
    {
        Gate::after(function (?User $user, string $ability, mixed $result, array $arguments): void {
            $response = $result instanceof Response ? $result : null;

            if ($result !== false && ($response === null || $response->allowed())) {
                return;
            }

            $target = $arguments[0] ?? null;

            SecurityLog::record('authorization.denied', [
                'user_id' => $user?->id,
                'ability' => $ability,
                'resource' => is_object($target) || is_string($target) ? class_basename($target) : null,
                'resource_ref' => $target instanceof Model ? ($target->getAttribute('uuid') ?? $target->getKey()) : null,
                'status' => $response?->status() ?? 403,
            ], 'warning');
        });
    }

    /**
     * Mots de passe : 12 caractères minimum et refus des mots de passe présents dans des fuites connues.
     */
    private function configurePasswords(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->uncompromised());
    }

    /**
     * Limiteurs de débit nommés. Les valeurs sont dans config/security.php (clé rate_limits).
     */
    private function configureRateLimiting(): void
    {
        // Connexion (utilisé par Fortify) : par couple email + IP, et par email toutes IP confondues.
        RateLimiter::for('login', function (Request $request): array {
            $email = $this->normalizedEmail($request);

            return [
                Limit::perMinute(self::limit('login.per_minute'))->by(Str::transliterate($email.'|'.$request->ip())),
                Limit::perHour(self::limit('login.per_hour_per_email'))->by('email:'.$email),
            ];
        });

        // Défi de double authentification : par connexion en cours.
        RateLimiter::for('two-factor', function (Request $request): array {
            $key = (string) ($request->session()->get('login.id') ?? $request->ip());

            return [
                Limit::perMinute(self::limit('two_factor.per_minute'))->by('minute:'.$key),
                Limit::perHour(self::limit('two_factor.per_hour'))->by('hour:'.$key),
            ];
        });

        // Inscription : par IP, et par email (une adresse existante reçoit un email à chaque tentative).
        RateLimiter::for('register', fn (Request $request): array => [
            Limit::perHour(self::limit('register.per_hour_per_ip'))->by('ip:'.$request->ip()),
            $this->perEmail($request, Limit::perHour(self::limit('register.per_hour_per_email'))),
        ]);

        // Demande de lien de réinitialisation : par email et par IP.
        RateLimiter::for('forgot-password', fn (Request $request): array => [
            $this->perEmail($request, Limit::perHour(self::limit('forgot_password.per_hour_per_email'))),
            Limit::perHour(self::limit('forgot_password.per_hour_per_ip'))->by('ip:'.$request->ip()),
        ]);

        // Réinitialisation avec un jeton : par IP.
        RateLimiter::for('reset-password', fn (Request $request): Limit => Limit::perHour(self::limit('reset_password.per_hour_per_ip'))->by((string) $request->ip()));

        // Changement et confirmation du mot de passe : par utilisateur connecté.
        RateLimiter::for('password-update', fn (Request $request): Limit => Limit::perMinute(self::limit('password_update.per_minute'))->by($this->userOrIp($request)));
        RateLimiter::for('password-confirm', fn (Request $request): Limit => Limit::perMinute(self::limit('password_confirm.per_minute'))->by($this->userOrIp($request)));

        // Renvoi du lien de vérification et clic sur ce lien : par utilisateur (ou IP).
        RateLimiter::for('verification', fn (Request $request): array => [
            Limit::perMinute(self::limit('verification.per_minute'))->by('minute:'.$this->userOrIp($request)),
            Limit::perHour(self::limit('verification.per_hour'))->by('hour:'.$this->userOrIp($request)),
        ]);

        // Limite générale de l'API : par utilisateur, ou par IP pour les invités.
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(self::limit('api.per_minute'))->by($this->userOrIp($request)));

        // Upload de médias (POST /media) : par utilisateur.
        RateLimiter::for('media-uploads', fn (Request $request): Limit => Limit::perMinute(self::limit('media_uploads.per_minute'))->by($this->userOrIp($request)));

        // Consultation des projets publiés (liste et détail comptés ensemble) : par utilisateur.
        RateLimiter::for('projects-browse', fn (Request $request): array => [
            Limit::perMinute(self::limit('projects_browse.per_minute'))->by('minute:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('projects_browse.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);

        // Suppression de compte (DELETE /me) : par utilisateur.
        RateLimiter::for('account-deletion', fn (Request $request): Limit => Limit::perMinute(self::limit('account_deletion.per_minute'))->by($this->userOrIp($request)));

        // Acceptation des documents légaux (CGU, confidentialité) : par utilisateur.
        RateLimiter::for('legal-acceptances', fn (Request $request): Limit => Limit::perHour(self::limit('legal_acceptances.per_hour'))->by($this->userOrIp($request)));

        // Demandes de mise en relation envoyées (propositions et contacts confondus) : par
        // utilisateur. Un dépassement est journalisé comme signe de spam.
        RateLimiter::for('match-requests', fn (Request $request): array => [
            $this->loggedLimit(Limit::perHour(self::limit('match_requests.per_hour')), 'match_requests.limit_exceeded', 'hour')
                ->by('hour:'.$this->userOrIp($request)),
            $this->loggedLimit(Limit::perDay(self::limit('match_requests.per_day')), 'match_requests.limit_exceeded', 'day')
                ->by('day:'.$this->userOrIp($request)),
        ]);

        // Messages envoyés : par utilisateur, toutes conversations confondues.
        RateLimiter::for('messages', fn (Request $request): array => [
            Limit::perMinute(self::limit('messages.per_minute'))->by('minute:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('messages.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);

        // Signalements, blocages et déblocages : par utilisateur.
        RateLimiter::for('reports', fn (Request $request): Limit => Limit::perDay(self::limit('reports.per_day'))->by($this->userOrIp($request)));
        RateLimiter::for('blocks', fn (Request $request): Limit => Limit::perDay(self::limit('blocks.per_day'))->by($this->userOrIp($request)));

        // Événements (module Events) : par utilisateur.
        RateLimiter::for('event-writes', fn (Request $request): array => [
            Limit::perHour(self::limit('event_writes.per_hour'))->by('hour:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('event_writes.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('event-submissions', fn (Request $request): array => [
            Limit::perHour(self::limit('event_submissions.per_hour'))->by('hour:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('event_submissions.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('event-registrations', fn (Request $request): array => [
            Limit::perHour(self::limit('event_registrations.per_hour'))->by('hour:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('event_registrations.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('event-registrant-removals', fn (Request $request): Limit => Limit::perDay(self::limit('event_registrant_removals.per_day'))->by($this->userOrIp($request)));
        RateLimiter::for('event-announcements', fn (Request $request): Limit => Limit::perDay(self::limit('event_announcements.per_day'))->by($this->userOrIp($request)));
        RateLimiter::for('event-moderation', fn (Request $request): Limit => Limit::perHour(self::limit('event_moderation.per_hour'))->by($this->userOrIp($request)));

        // Articles (module Blog) : par utilisateur.
        RateLimiter::for('article-writes', fn (Request $request): array => [
            Limit::perHour(self::limit('article_writes.per_hour'))->by('hour:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('article_writes.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('article-submissions', fn (Request $request): array => [
            Limit::perHour(self::limit('article_submissions.per_hour'))->by('hour:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('article_submissions.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('blog-moderation', fn (Request $request): Limit => Limit::perHour(self::limit('blog_moderation.per_hour'))->by($this->userOrIp($request)));

        // Attribution et retrait du statut premium (module Accounts) : par admin.
        RateLimiter::for('premium-admin', fn (Request $request): Limit => Limit::perHour(self::limit('premium_admin.per_hour'))->by($this->userOrIp($request)));

        // Suivi administratif (module Compliance).
        RateLimiter::for('compliance-rules-admin', fn (Request $request): Limit => Limit::perHour(self::limit('compliance_rules_admin.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('compliance-writes', fn (Request $request): array => [
            Limit::perHour(self::limit('compliance_writes.per_hour'))->by('hour:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('compliance_writes.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('compliance-companies', fn (Request $request): Limit => Limit::perDay(self::limit('compliance_companies.per_day'))->by($this->userOrIp($request)));
        RateLimiter::for('compliance-revenue', fn (Request $request): Limit => Limit::perHour(self::limit('compliance_revenue.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('compliance-exports', fn (Request $request): Limit => Limit::perHour(self::limit('compliance_exports.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('compliance-feed-manage', fn (Request $request): Limit => Limit::perDay(self::limit('compliance_feed_manage.per_day'))->by($this->userOrIp($request)));

        // Signalements et modération (module Moderation). Le signalement est ouvert sans compte :
        // limites par IP, plus une limite par compte quand le signalant est connecté (la limite
        // par email est comptée dans SubmitNotice). Un dépassement est journalisé.
        RateLimiter::for('moderation-notices', function (Request $request): array {
            $limits = [
                $this->loggedLimit(Limit::perHour(self::limit('moderation_notices.per_hour_per_ip')), 'moderation.notice_limit_exceeded', 'hour')
                    ->by('hour:ip:'.$request->ip()),
                $this->loggedLimit(Limit::perDay(self::limit('moderation_notices.per_day_per_ip')), 'moderation.notice_limit_exceeded', 'day')
                    ->by('day:ip:'.$request->ip()),
            ];
            $userId = $request->user()?->getAuthIdentifier();

            if ($userId !== null) {
                $limits[] = $this->loggedLimit(Limit::perDay(self::limit('moderation_notices.per_day_per_user')), 'moderation.notice_limit_exceeded', 'day')
                    ->by('day:user:'.$userId);
            }

            return $limits;
        });
        RateLimiter::for('moderation-lookups', fn (Request $request): Limit => Limit::perHour(self::limit('moderation_lookups.per_hour'))->by('ip:'.$request->ip()));
        RateLimiter::for('moderation-appeals', fn (Request $request): Limit => Limit::perDay(self::limit('moderation_appeals.per_day'))->by($this->userOrIp($request)));
        RateLimiter::for('moderation-admin', fn (Request $request): Limit => Limit::perHour(self::limit('moderation_admin.per_hour'))->by($this->userOrIp($request)));

        // Marketplace (lot 3) : par utilisateur.
        RateLimiter::for('seller-profile-writes', fn (Request $request): Limit => Limit::perHour(self::limit('seller_profile_writes.per_hour'))->by($this->userOrIp($request)));
        foreach (['offer-writes' => 'offer_writes', 'quote-writes' => 'quote_writes', 'quote-sends' => 'quote_sends', 'quote-pdfs' => 'quote_pdfs'] as $name => $key) {
            RateLimiter::for($name, fn (Request $request): array => [
                Limit::perHour(self::limit($key.'.per_hour'))->by('hour:'.$this->userOrIp($request)),
                Limit::perDay(self::limit($key.'.per_day'))->by('day:'.$this->userOrIp($request)),
            ]);
        }
        RateLimiter::for('quote-decisions', fn (Request $request): Limit => Limit::perHour(self::limit('quote_decisions.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('quote-comparisons', fn (Request $request): Limit => Limit::perHour(self::limit('quote_comparisons.per_hour'))->by($this->userOrIp($request)));

        // Rendez-vous (lot 4) : par utilisateur ; créneaux de la page publique : par IP.
        foreach (['appointment-settings-writes' => 'appointment_settings_writes', 'appointment-bookings' => 'appointment_bookings', 'workspace-writes' => 'workspace_writes', 'copilot-generations' => 'copilot_generations'] as $name => $key) {
            RateLimiter::for($name, fn (Request $request): array => [
                Limit::perHour(self::limit($key.'.per_hour'))->by('hour:'.$this->userOrIp($request)),
                Limit::perDay(self::limit($key.'.per_day'))->by('day:'.$this->userOrIp($request)),
            ]);
        }
        RateLimiter::for('appointment-slots', fn (Request $request): array => [
            Limit::perMinute(self::limit('appointment_slots.per_minute'))->by('minute:'.$this->userOrIp($request)),
            Limit::perDay(self::limit('appointment_slots.per_day'))->by('day:'.$this->userOrIp($request)),
        ]);
        RateLimiter::for('appointment-public-slots', fn (Request $request): Limit => Limit::perMinute(self::limit('appointment_slots.public_per_minute_per_ip'))->by('ip:'.$request->ip()));
        RateLimiter::for('appointment-decisions', fn (Request $request): Limit => Limit::perHour(self::limit('appointment_decisions.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('appointment-ics', fn (Request $request): Limit => Limit::perHour(self::limit('appointment_ics.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('ai-admin', fn (Request $request): Limit => Limit::perHour(self::limit('ai_admin.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('onboarding-writes', fn (Request $request): Limit => Limit::perHour(self::limit('onboarding_writes.per_hour'))->by($this->userOrIp($request)));
        RateLimiter::for('agenda', fn (Request $request): Limit => Limit::perMinute(self::limit('agenda.per_minute'))->by($this->userOrIp($request)));
        RateLimiter::for('agenda-feed-manage', fn (Request $request): Limit => Limit::perDay(self::limit('agenda_feed_manage.per_day'))->by($this->userOrIp($request)));
        RateLimiter::for('agenda-feed', fn (Request $request): array => [
            Limit::perHour(self::limit('agenda_feed.per_hour_per_feed'))->by('feed:'.substr((string) $request->route('token'), 0, 16)),
            Limit::perHour(self::limit('agenda_feed.per_hour_per_ip'))->by('ip:'.$request->ip()),
        ]);

        // Abonnement au calendrier (route publique) : par abonnement, compté sur le sélecteur du
        // jeton (sa partie publique, jamais le secret), et par IP.
        RateLimiter::for('compliance-feed', fn (Request $request): array => [
            Limit::perHour(self::limit('compliance_feed.per_hour_per_feed'))->by('feed:'.substr((string) $request->route('token'), 0, 16)),
            Limit::perHour(self::limit('compliance_feed.per_hour_per_ip'))->by('ip:'.$request->ip()),
        ]);
    }

    /**
     * Limite dont le dépassement écrit en plus un avertissement dédié dans le journal security.
     * La réponse reste celle de Laravel (ThrottleRequestsException : 429, Retry-After, et
     * l'événement générique "rate_limit.exceeded").
     */
    private function loggedLimit(Limit $limit, string $event, string $window): Limit
    {
        return $limit->response(function (Request $request, array $headers) use ($event, $window): never {
            SecurityLog::record($event, ['window' => $window], 'warning', $request);

            throw new ThrottleRequestsException('Too Many Attempts.', null, $headers);
        });
    }

    /**
     * Valeur d'une limite de config/security.php (rate_limits.<clé>).
     */
    public static function limit(string $key): int
    {
        return (int) config('security.rate_limits.'.$key);
    }

    private function normalizedEmail(Request $request): string
    {
        return Str::lower(trim($request->string(Fortify::email())->toString()));
    }

    /**
     * Limite par adresse email ; sans email, la requête échouera à la validation et seule la
     * limite par IP s'applique.
     */
    private function perEmail(Request $request, Limit $limit): Limit
    {
        $email = $this->normalizedEmail($request);

        return $email === '' ? Limit::none() : $limit->by('email:'.$email);
    }

    private function userOrIp(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? 'user:'.$id : 'ip:'.$request->ip();
    }
}
