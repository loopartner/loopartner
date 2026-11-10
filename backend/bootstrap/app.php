<?php

use App\Http\Middleware\SecurityHeaders;
use App\Support\SecurityLog;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Commandes Artisan des modules (app/Modules/<Module>/Console/Commands), en plus de
    // app/Console/Commands déjà chargé par défaut.
    ->withCommands(glob(__DIR__.'/../app/Modules/*/Console/Commands', GLOB_ONLYDIR) ?: [])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // Global : couvre aussi les 404 des routes inconnues, qui ne passent par aucun groupe.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Blocage par une limite de débit : journalisé (canal security), puis rendu normalement.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request): null {
            SecurityLog::record('rate_limit.exceeded', [
                'retry_after' => (int) ($e->getHeaders()['Retry-After'] ?? 0),
            ], 'warning', $request);

            return null;
        });
    })->create();
