<?php

use App\Support\PlatformStatus;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Battement du scheduler : le conteneur « scheduler » (schedule:work) l'exécute
// chaque minute ; la page d'accueil affiche son âge.
Schedule::call(function (): void {
    Cache::forever(PlatformStatus::SCHEDULER_KEY, now()->toIso8601String());
})->everyMinute()->name('devops-heartbeat');
