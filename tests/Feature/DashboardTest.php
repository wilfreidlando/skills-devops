<?php

namespace Tests\Feature;

use App\Jobs\PingWorker;
use App\Support\PlatformStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_affiche_l_etat_et_compte_les_visites(): void
    {
        $this->get('/')->assertOk()->assertSee('Carnet DevOps')->assertSee('Visites enregistrées');
        $this->get('/');

        $this->assertDatabaseCount('visits', 2);
    }

    public function test_le_statut_json_expose_chaque_brique(): void
    {
        $this->getJson('/status')
            ->assertOk()
            ->assertJsonStructure([
                'environment' => ['deployment', 'version', 'container'],
                'request' => ['host', 'https', 'client_ip'],
                'database' => ['ok'],
                'cache' => ['ok'],
                'queue' => ['ok'],
                'scheduler' => ['ok'],
            ])
            ->assertJsonPath('database.ok', true)
            ->assertJsonPath('cache.ok', true);
    }

    public function test_le_bouton_envoie_un_job_dans_la_file(): void
    {
        Queue::fake();

        $this->post('/ping')->assertRedirect('/');

        Queue::assertPushed(PingWorker::class);
    }

    public function test_le_worker_note_le_job_traite(): void
    {
        (new PingWorker)->handle();

        $last = Cache::get(PlatformStatus::LAST_JOB_KEY);
        $this->assertIsArray($last);
        $this->assertSame(gethostname(), $last['worker']);
    }

    public function test_le_scheduler_bat_chaque_minute(): void
    {
        $this->artisan('schedule:run')->assertSuccessful();

        $this->getJson('/status')->assertJsonPath('scheduler.ok', true);
    }

    public function test_https_et_ip_reelle_derriere_nginx_proxy(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.7'])
            ->getJson('/status')
            ->assertJsonPath('request.https', true)
            ->assertJsonPath('request.client_ip', '203.0.113.7');
    }
}
