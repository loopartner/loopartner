<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configurePasswords();
        $this->configureRateLimiting();
    }

    /**
     * Mots de passe : 12 caractères minimum et refus des mots de passe présents dans des fuites connues.
     */
    private function configurePasswords(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->uncompromised());
    }

    /**
     * Limiteurs de débit nommés.
     */
    private function configureRateLimiting(): void
    {
        // Connexion : 5 tentatives par minute pour un couple email + IP (utilisé par Fortify).
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower($request->string(Fortify::username())->toString());

            return Limit::perMinute(5)->by(Str::transliterate($email.'|'.$request->ip()));
        });

        // Défi de double authentification : 5 tentatives par minute pour une connexion en cours.
        RateLimiter::for('two-factor', function (Request $request): Limit {
            return Limit::perMinute(5)->by($request->session()->get('login.id') ?? $request->ip());
        });

        // Limite générale de l'API : 60 requêtes par minute par utilisateur, ou par IP pour les invités.
        RateLimiter::for('api', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?? $request->ip());
        });
    }
}
