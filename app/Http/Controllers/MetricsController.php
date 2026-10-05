<?php

namespace App\Http\Controllers;

use App\Support\PlatformStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Métriques de l'application au format texte de Prometheus.
 *
 * Alloy les lit toutes les 30 secondes sur le réseau « observability » (labels
 * observability.metrics.* du compose). Jamais publiques : voir OnlyFromPrivateNetwork.
 */
class MetricsController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $status = PlatformStatus::collect($request);
        $out = [];

        $this->metric($out, 'skills_devops_info', 'gauge', "Informations de version (valeur toujours 1).", 1, [
            'deployment' => (string) $status['environment']['deployment'],
            'version' => (string) $status['environment']['version'],
        ]);
        $this->metric($out, 'skills_devops_up', 'gauge', "1 si l'application répond.", 1);
        $this->metric($out, 'skills_devops_database_up', 'gauge', "1 si la base de données répond.", $status['database']['ok'] ? 1 : 0);
        $this->metric($out, 'skills_devops_cache_up', 'gauge', "1 si le cache répond.", $status['cache']['ok'] ? 1 : 0);

        if ($status['database']['visits'] !== null) {
            $this->metric($out, 'skills_devops_visits_total', 'counter', "Visites enregistrées dans la base.", (int) $status['database']['visits']);
        }

        $failed = $this->failedJobs();
        if ($failed !== null) {
            $this->metric($out, 'skills_devops_failed_jobs_total', 'gauge', "Jobs en échec enregistrés (table failed_jobs).", $failed);
        }

        $size = $this->queueSize();
        if ($size !== null) {
            $this->metric($out, 'skills_devops_queue_size', 'gauge', "Jobs en attente dans la file par défaut.", $size);
        }

        $scheduler = $status['scheduler'];
        $this->metric($out, 'skills_devops_scheduler_up', 'gauge', "1 si le planificateur a battu il y a moins de 3 minutes.", $scheduler['ok'] ? 1 : 0);
        if ($scheduler['age_seconds'] !== null) {
            $this->metric($out, 'skills_devops_scheduler_heartbeat_age_seconds', 'gauge', "Âge du dernier battement du planificateur.", (int) $scheduler['age_seconds']);
        }

        return response(implode("\n", $out)."\n", 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    /**
     * @param  list<string>  $out
     * @param  array<string, string>  $labels
     */
    private function metric(array &$out, string $name, string $type, string $help, int|float $value, array $labels = []): void
    {
        $pairs = [];
        foreach ($labels as $key => $label) {
            $pairs[] = $key.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $label).'"';
        }

        $out[] = '# HELP '.$name.' '.$help;
        $out[] = '# TYPE '.$name.' '.$type;
        $out[] = $name.($pairs === [] ? '' : '{'.implode(',', $pairs).'}').' '.$value;
    }

    private function failedJobs(): ?int
    {
        try {
            return Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function queueSize(): ?int
    {
        try {
            return Queue::size();
        } catch (Throwable) {
            return null;
        }
    }
}
