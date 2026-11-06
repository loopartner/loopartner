<?php

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Charge les routes de chaque module (app/Modules/<Module>/routes.php) sous /api/v1.
     */
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        foreach ($this->moduleRouteFiles() as $routeFile) {
            Route::prefix('api/v1')
                ->middleware(['api', 'throttle:api'])
                ->group($routeFile);
        }
    }

    /**
     * @return list<string>
     */
    private function moduleRouteFiles(): array
    {
        $files = glob(app_path('Modules/*/routes.php'));

        return $files === false ? [] : $files;
    }
}
