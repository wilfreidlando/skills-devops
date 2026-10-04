<?php

namespace App\Jobs;

use App\Support\PlatformStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Job témoin : envoyé depuis la page web, exécuté par le conteneur « worker ».
 * Il note l'heure et le nom du conteneur qui l'a traité : la preuve que la file
 * d'attente (Redis) et le worker fonctionnent.
 */
class PingWorker implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        Cache::forever(PlatformStatus::LAST_JOB_KEY, [
            'at' => now()->toIso8601String(),
            'worker' => (string) gethostname(),
        ]);
    }
}
