<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_reseau_prive_lit_les_metriques_au_format_prometheus(): void
    {
        $response = $this->get('/metrics');

        $response->assertOk();
        $this->assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        $response->assertSee('# TYPE skills_devops_up gauge', false)
            ->assertSee("skills_devops_up 1\n", false)
            ->assertSee("skills_devops_database_up 1\n", false)
            ->assertSee("skills_devops_cache_up 1\n", false)
            ->assertSee('skills_devops_info{deployment="', false);
    }

    public function test_une_requete_venue_d_internet_est_refusee(): void
    {
        // nginx-proxy ajoute toujours X-Forwarded-For : les métriques ne sont jamais publiques.
        $this->withHeaders(['X-Forwarded-For' => '203.0.113.9'])->get('/metrics')->assertNotFound();
    }

    public function test_les_compteurs_suivent_la_base(): void
    {
        $this->get('/');
        $this->get('/');
        DB::table('failed_jobs')->insert([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'test',
            'failed_at' => now(),
        ]);

        $this->get('/metrics')
            ->assertSee("skills_devops_visits_total 2\n", false)
            ->assertSee("skills_devops_failed_jobs_total 1\n", false);
    }

    public function test_aucune_session_n_est_ouverte_a_chaque_collecte(): void
    {
        $this->get('/metrics')->assertCookieMissing((string) config('session.cookie'));
    }
}
