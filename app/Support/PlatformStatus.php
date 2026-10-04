<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ce que l'application peut observer d'elle-même, une fois déployée sur le VPS.
 * Chaque information correspond à une brique de la plateforme
 * (vcam-infra-deploy) : environnement, version, base, cache, file d'attente,
 * tâches planifiées, proxy HTTPS.
 */
class PlatformStatus
{
    public const LAST_JOB_KEY = 'devops:last_job';

    public const SCHEDULER_KEY = 'devops:scheduler_heartbeat';

    /** @return array<string, mixed> */
    public static function collect(Request $request): array
    {
        return [
            'environment' => [
                'deployment' => (string) config('devops.deployment'),
                'app_env' => (string) config('app.env'),
                'version' => (string) config('devops.version'),
                'container' => (string) gethostname(),
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
            ],
            'request' => [
                'host' => $request->getHost(),
                'https' => $request->isSecure(),
                'client_ip' => $request->ip(),
                'forwarded_for' => (string) $request->header('X-Forwarded-For', ''),
            ],
            'database' => self::database(),
            'cache' => self::cache(),
            'queue' => self::queue(),
            'scheduler' => self::scheduler(),
        ];
    }

    /** @return array{ok: bool, detail: string, visits: int|null} */
    private static function database(): array
    {
        try {
            $visits = (int) DB::table('visits')->count();

            return ['ok' => true, 'detail' => DB::connection()->getDriverName(), 'visits' => $visits];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'visits' => null];
        }
    }

    /** @return array{ok: bool, detail: string} */
    private static function cache(): array
    {
        try {
            Cache::put('devops:cache_probe', 'ok', 60);

            return ['ok' => Cache::get('devops:cache_probe') === 'ok', 'detail' => (string) config('cache.default')];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, detail: string, last: array{at: string, worker: string}|null} */
    private static function queue(): array
    {
        try {
            /** @var array{at: string, worker: string}|null $last */
            $last = Cache::get(self::LAST_JOB_KEY);

            return [
                'ok' => $last !== null,
                'detail' => (string) config('queue.default'),
                'last' => $last,
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => $e->getMessage(), 'last' => null];
        }
    }

    /** @return array{ok: bool, last: string|null, age_seconds: int|null} */
    private static function scheduler(): array
    {
        try {
            $last = Cache::get(self::SCHEDULER_KEY);
            if (! is_string($last)) {
                return ['ok' => false, 'last' => null, 'age_seconds' => null];
            }
            $age = (int) Carbon::parse($last)->diffInSeconds(now(), true);

            // schedule:work exécute la tâche chaque minute : au-delà de 3 minutes,
            // le conteneur scheduler ne tourne plus.
            return ['ok' => $age <= 180, 'last' => $last, 'age_seconds' => $age];
        } catch (Throwable) {
            return ['ok' => false, 'last' => null, 'age_seconds' => null];
        }
    }
}
