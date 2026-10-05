<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Controllers\MetricsController;
use App\Http\Middleware\OnlyFromPrivateNetwork;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Hors du groupe « web » : pas de session ni de cookie à chaque collecte (toutes les 30 s).
            // Réservé au réseau privé : jamais de métriques publiques (voir OnlyFromPrivateNetwork).
            Route::get('/metrics', MetricsController::class)
                ->middleware(OnlyFromPrivateNetwork::class)
                ->name('metrics');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Derrière nginx-proxy : HTTPS et IP du visiteur viennent des en-têtes
        // X-Forwarded-*. Seuls nginx-proxy et le réseau privé du projet
        // atteignent ce conteneur.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
