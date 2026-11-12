<?php

namespace App\Providers;

use App\Models\User;
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
        //
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

        // Attribution et retrait du statut premium (module Accounts) : par admin.
        RateLimiter::for('premium-admin', fn (Request $request): Limit => Limit::perHour(self::limit('premium_admin.per_hour'))->by($this->userOrIp($request)));
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
