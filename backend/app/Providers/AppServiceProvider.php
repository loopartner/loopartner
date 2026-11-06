<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
    }

    /**
     * Mots de passe : 12 caractères minimum et refus des mots de passe présents dans des fuites connues.
     */
    private function configurePasswords(): void
    {
        Password::defaults(fn (): Password => Password::min(12)->uncompromised());
    }
}
