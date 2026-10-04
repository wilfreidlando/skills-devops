@php
    $env = $status['environment'];
    $req = $status['request'];
    $db = $status['database'];
    $cache = $status['cache'];
    $queue = $status['queue'];
    $sched = $status['scheduler'];
    $ok = fn (bool $v) => $v ? 'ok' : 'ko';
@endphp
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Carnet DevOps — {{ $env['deployment'] }}</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#1d2433; --muted:#5b6475; --line:#e3e6ec; --ok:#137a3e; --okbg:#e6f4ec; --ko:#a32424; --kobg:#fbeaea; --accent:#2457c5; }
        @media (prefers-color-scheme: dark) { :root { --bg:#12151b; --card:#1b2029; --ink:#e7eaf0; --muted:#9aa3b5; --line:#2b3240; --ok:#5ad08a; --okbg:#173326; --ko:#ff8a8a; --kobg:#3a1c1c; --accent:#7aa2ff; } }
        * { box-sizing: border-box; }
        body { margin:0; font:15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background:var(--bg); color:var(--ink); }
        main { max-width: 1040px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 26px; margin: 0 0 4px; }
        h2 { font-size: 18px; margin: 32px 0 12px; }
        .sub { color: var(--muted); margin: 0 0 20px; }
        .badge { display:inline-block; padding:2px 10px; border-radius:999px; font-weight:600; font-size:13px; background:var(--accent); color:#fff; }
        .grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 12px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:14px 16px; }
        .card h3 { font-size:14px; margin:0 0 6px; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
        .state { display:inline-block; font-weight:700; padding:1px 8px; border-radius:6px; font-size:13px; }
        .state.ok { color:var(--ok); background:var(--okbg); } .state.ko { color:var(--ko); background:var(--kobg); }
        .v { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:13px; word-break: break-all; }
        dl { margin:0; display:grid; grid-template-columns:auto 1fr; gap:4px 12px; }
        dt { color:var(--muted); } dd { margin:0; }
        button { font:inherit; padding:8px 14px; border-radius:8px; border:0; background:var(--accent); color:#fff; cursor:pointer; }
        .flash { background:var(--okbg); color:var(--ok); padding:8px 12px; border-radius:8px; margin: 0 0 16px; }
        details { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:10px 16px; margin-bottom:8px; }
        summary { cursor:pointer; font-weight:600; }
        pre { background:var(--bg); border:1px solid var(--line); border-radius:8px; padding:10px; overflow-x:auto; font-size:13px; }
        footer { margin-top: 32px; color: var(--muted); font-size: 13px; }
    </style>
</head>
<body>
<main>
    <h1>Carnet DevOps <span class="badge">{{ $env['deployment'] }}</span></h1>
    <p class="sub">Cette page montre, en direct, ce que la plateforme du VPS fait pour une application.
        Chaque bloc correspond à une notion ; en bas, la commande pour la vérifier vous-même sur le serveur.</p>

    @if (session('pinged'))
        <p class="flash">Job envoyé dans la file d'attente. Rechargez la page dans 2 à 3 secondes : le bloc « Worker » doit afficher l'heure du traitement.</p>
    @endif

    <h2>État en direct</h2>
    <div class="grid">
        <div class="card">
            <h3>Version déployée</h3>
            <dl>
                <dt>Commit</dt><dd class="v">{{ \Illuminate\Support\Str::limit($env['version'], 12, '') }}</dd>
                <dt>Conteneur</dt><dd class="v">{{ $env['container'] }}</dd>
                <dt>APP_ENV</dt><dd class="v">{{ $env['app_env'] }}</dd>
                <dt>PHP / Laravel</dt><dd class="v">{{ $env['php'] }} / {{ $env['laravel'] }}</dd>
            </dl>
        </div>
        <div class="card">
            <h3>HTTPS et proxy</h3>
            <dl>
                <dt>Hôte</dt><dd class="v">{{ $req['host'] }}</dd>
                <dt>HTTPS</dt><dd><span class="state {{ $ok($req['https']) }}">{{ $req['https'] ? 'oui' : 'non' }}</span></dd>
                <dt>Votre IP</dt><dd class="v">{{ $req['client_ip'] }}</dd>
            </dl>
        </div>
        <div class="card">
            <h3>Base de données</h3>
            <p><span class="state {{ $ok($db['ok']) }}">{{ $db['ok'] ? 'connectée' : 'en panne' }}</span> <span class="v">{{ $db['detail'] }}</span></p>
            @if ($db['visits'] !== null)
                <p>Visites enregistrées : <strong>{{ $db['visits'] }}</strong><br><small>Ce compteur survit aux déploiements : les données sont dans un volume.</small></p>
            @endif
        </div>
        <div class="card">
            <h3>Cache (Redis)</h3>
            <p><span class="state {{ $ok($cache['ok']) }}">{{ $cache['ok'] ? 'opérationnel' : 'en panne' }}</span> <span class="v">{{ $cache['detail'] }}</span></p>
        </div>
        <div class="card">
            <h3>Worker (file d'attente)</h3>
            @if ($queue['last'])
                <p><span class="state ok">a traité un job</span><br>
                    <small>le {{ $queue['last']['at'] }}<br>par <span class="v">{{ $queue['last']['worker'] }}</span></small></p>
            @else
                <p><span class="state ko">aucun job traité</span></p>
            @endif
            <form method="post" action="{{ route('ping') }}">@csrf<button type="submit">Envoyer un job</button></form>
        </div>
        <div class="card">
            <h3>Scheduler</h3>
            @if ($sched['last'])
                <p><span class="state {{ $ok($sched['ok']) }}">{{ $sched['ok'] ? 'actif' : 'arrêté ?' }}</span><br>
                    <small>dernier passage il y a {{ $sched['age_seconds'] }} s</small></p>
            @else
                <p><span class="state ko">pas encore passé</span><br><small>il passe chaque minute</small></p>
            @endif
        </div>
    </div>

    <h2>Ce qu'il faut retenir (et comment le vérifier)</h2>

    <details>
        <summary>1. Un sous-domaine sans rien créer dans le DNS</summary>
        <p>La zone DNS (chez Contabo) contient <code>*.visibilitycam.com → IP du VPS</code>. Tout nom à un niveau arrive donc sur le serveur. Cette app n'a eu besoin que d'un nom libre, déclaré dans son <code>.env</code> (<code>APP_PUBLIC_HOST</code>).</p>
        <pre>dig +short {{ $req['host'] }}                         # → l'IP du VPS
/app/vps-platform/bin/vps-hosts.sh --free autre-nom.visibilitycam.com</pre>
    </details>
    <details>
        <summary>2. nginx-proxy envoie la visite au bon conteneur</summary>
        <p>Le conteneur <code>app</code> déclare <code>VIRTUAL_HOST</code> : nginx-proxy le voit et route ce nom vers lui. Aucun port ouvert sur le serveur.</p>
        @verbatim<pre>docker inspect skills-devops-prod-app-1 --format '{{range .Config.Env}}{{println .}}{{end}}' | grep VIRTUAL_HOST</pre>@endverbatim
    </details>
    <details>
        <summary>3. HTTPS automatique (Let's Encrypt)</summary>
        <p>acme-companion lit <code>LETSENCRYPT_HOST</code> et obtient le certificat en 1 à 2 minutes, puis le renouvelle seul.</p>
        <pre>docker logs nginx-proxy-acme 2>&1 | grep {{ $req['host'] }} | tail -5</pre>
    </details>
    <details>
        <summary>4. Staging automatique, production par promotion de la même image</summary>
        <p><code>deploy.sh</code> construit une image par commit. Le staging la reçoit automatiquement ; la production reçoit exactement la même, quand vous le décidez. Le commit affiché ci-dessus doit être identique en staging et en prod après une promotion.</p>
        <pre>cd /app/skills-devops/staging && /app/vps-platform/bin/deploy.sh watch
cd /app/skills-devops/prod && /app/vps-platform/bin/deploy.sh promote
/app/vps-platform/bin/deploy.sh status</pre>
    </details>
    <details>
        <summary>5. Migrations avant la bascule, retour arrière automatique</summary>
        <p>Si une migration échoue, rien n'est basculé. Si la nouvelle version ne répond pas sur <code>/up</code>, l'ancienne revient seule. Retour arrière manuel :</p>
        <pre>cd /app/skills-devops/prod && /app/vps-platform/bin/deploy.sh rollback prod
tail -20 /var/lib/vps-platform/skills-devops/deploy.log</pre>
    </details>
    <details>
        <summary>6. Sauvegardes chiffrées</summary>
        <p>Une sauvegarde est prise avant chaque mise en production, et chaque nuit. Le compteur de visites ci-dessus est dedans.</p>
        <pre>docker run --rm -v skills-devops-prod_backups:/b busybox ls -lh /b</pre>
    </details>
    <details>
        <summary>7. Un processus par conteneur, relancé par Docker</summary>
        <p><code>app</code> (web), <code>worker</code> (file d'attente), <code>scheduler</code> (tâches planifiées), <code>db</code>, <code>redis</code> : si l'un s'arrête, Docker le relance. Essayez avec le worker, puis renvoyez un job :</p>
        <pre>docker restart skills-devops-prod-worker-1
docker ps --filter label=com.docker.compose.project=skills-devops-prod</pre>
    </details>
    <details>
        <summary>8. Journaux centralisés</summary>
        <p>Les conteneurs portent le label <code>observability.enable=true</code> : leurs journaux arrivent dans Grafana (<code>app=skills-devops</code>), sans rien configurer d'autre.</p>
        <pre>docker logs skills-devops-prod-app-1 --tail 20</pre>
    </details>

    <footer>JSON du même état : <a href="{{ route('status') }}">/status</a> · santé : <a href="/up">/up</a></footer>
</main>
</body>
</html>
