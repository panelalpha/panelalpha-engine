<?php

/**
 * Deploy a list of apps across several engine hosts and keep checking them.
 *
 *   php scripts/tools/app-fleet.php --hosts=hosts.txt --apps=apps.txt --state=DIR [options]
 *
 * hosts.txt, one engine per line (`#` comments):
 *   small    root@engine1.example.com  api=https://203.0.113.58:2011/api  memory_limit=1200
 *   shared   root@engine2.example.com  read_only
 * Keys: api (default https://<ssh host>:2011/api), token (default: read over ssh
 * from token_file=/root/.engine-api-token), read_only (sampled and shown, never
 * deployed to), max_deploys (default: the engine's running queue workers),
 * max_apps, memory_limit, target_load=1.0, min_mem_mb=1024, reserve_mem_mb=1536,
 * min_disk_gb=10, max_mem_pressure=20, ramp_seconds=45.
 *
 * apps.txt, one git URL per line, optionally followed by a name:
 *   https://github.com/octocat/Spoon-Knife
 *   https://git.sr.ht/~bouncepaw/betula  betula
 *
 * The loop, every --tick seconds:
 *   1. samples every host over ssh: load, MemAvailable, disk, memory pressure, queue workers
 *   2. starts the next queued app on a host with headroom (one per host per tick)
 *   3. follows running deploys (task status + log), all hosts in parallel
 *   4. every --interval seconds checks each deployed app, in parallel:
 *      GET /projects/{u}/app/health (ports, serving, domain, checks) + /containers
 *   5. deletes an app's project after its --checks checks, or after a failed
 *      deploy, which frees the host for the next app
 *   6. rewrites <state>/report.md and report.json; events go to stdout and events.log
 * The run ends when every app has been tested and deleted.
 *
 * Verdict per app: OK (every check clean), FLAKY (some), PROBLEM (none),
 * DEPLOY FAILED (with the engine's reason and the last log lines).
 *
 * Options:
 *   --checks=3        checks per deployed app before it is deleted
 *   --interval=30     seconds between two checks of one app
 *   --timeout=1800    cancel a deploy still running after this many seconds
 *   --memory-limit=MB per-project memory cap (hosts.txt memory_limit wins)
 *   --tag=fleet       test accounts use <tag>-<app>@example.com
 *   --dry-run         sample hosts and print the plan; change nothing
 *   --cleanup         delete every <tag>-* account on writable hosts, then exit
 *
 * State survives a restart: an app whose account exists (same email) is adopted,
 * checked and deleted rather than redeployed. Ctrl-C saves state and exits;
 * deploys already started keep running on the engine until the next start.
 */

declare(strict_types=1);

// Local time in the output; a php.ini default of UTC would hide it.
if (is_link('/etc/localtime')) {
    date_default_timezone_set(preg_replace('#^.*/zoneinfo/#', '', (string) readlink('/etc/localtime')));
}

const SAMPLE_SCRIPT = <<<'SH'
echo cpus=$(nproc)
read l1 l5 _ < /proc/loadavg; echo load1=$l1
awk '/^MemTotal:/{print "mem_total_kb="$2} /^MemAvailable:/{print "mem_avail_kb="$2}' /proc/meminfo
df -Pk /home | awk 'NR==2{print "disk_free_kb="$4}'
[ -r /proc/pressure/memory ] && awk '/^some/{split($2,a,"="); print "psi_mem="a[2]}' /proc/pressure/memory
echo workers=$(pgrep -fc 'artisan queue:work' || true)
SH;

const HOST_DEFAULTS = [
    'read_only' => false, 'max_deploys' => 0, 'max_apps' => 0, 'memory_limit' => 0,
    'target_load' => 1.0, 'min_mem_mb' => 1024, 'reserve_mem_mb' => 1536, 'warmup_seconds' => 180,
    'min_disk_gb' => 10, 'max_mem_pressure' => 20, 'ramp_seconds' => 45,
    'token_file' => '/root/.engine-api-token',
];

$opt = getopt('', ['hosts:', 'apps:', 'state:', 'interval:', 'checks:', 'timeout:',
    'memory-limit:', 'tag:', 'tick:', 'dry-run', 'cleanup', 'help']);
if (isset($opt['help']) || !isset($opt['hosts'])) {
    fwrite(STDERR, "usage: php scripts/tools/app-fleet.php --hosts=hosts.txt --apps=apps.txt --state=DIR\n"
        . "       [--checks=3] [--interval=30] [--timeout=1800] [--memory-limit=MB]\n"
        . "       [--tag=fleet] [--dry-run] [--cleanup]   (details at the top of this file)\n");
    exit(2);
}
$cfg = [
    'interval' => (int) ($opt['interval'] ?? 30),
    'checks' => (int) ($opt['checks'] ?? 3),
    'timeout' => (int) ($opt['timeout'] ?? 1800),
    'memory_limit' => (int) ($opt['memory-limit'] ?? 0),
    'tag' => (string) ($opt['tag'] ?? 'fleet'),
    'tick' => (int) ($opt['tick'] ?? 15),
];
if (!preg_match('/^[a-z0-9-]+$/', $cfg['tag'])) {
    fail('--tag must be [a-z0-9-]+');
}
if ($cfg['checks'] < 1) {
    fail('--checks must be at least 1');
}

$hosts = loadHosts($opt['hosts']);
foreach ($hosts as &$h) {
    if ($h['token'] === '' && !$h['read_only']) {
        [$code, $out] = ssh($h['ssh'], 'cat ' . escapeshellarg($h['token_file']));
        $h['token'] = trim($out);
        if ($code !== 0 || $h['token'] === '') {
            fail("{$h['name']}: cannot read the API token from {$h['token_file']}");
        }
    }
}
unset($h);
sampleHosts($hosts);

if (isset($opt['cleanup'])) {
    foreach (writable($hosts) as $name => $h) {
        foreach (taggedAccounts($h, $cfg['tag']) as $p) {
            [$st] = api($h, 'DELETE', '/projects/' . $p['username'], null, 900);
            say("{$name}: deleted {$p['username']} ({$p['email']}) -> HTTP {$st}");
        }
    }
    exit(0);
}

if (!isset($opt['apps'], $opt['state'])) {
    fail('--apps and --state are required');
}
$stateDir = rtrim($opt['state'], '/');
@mkdir($stateDir, 0775, true);
$stateFile = "$stateDir/state.json";
$state = is_file($stateFile) ? json_decode((string) file_get_contents($stateFile), true) : [];
$state += ['apps' => [], 'deletes' => []];

// The apps file is the source of truth for what to run; state keeps what happened.
foreach (loadApps($opt['apps']) as $slug => $app) {
    $state['apps'][$slug] = ($state['apps'][$slug] ?? []) + $app + [
        'phase' => 'queued', 'host' => null, 'project' => null, 'email' => "{$cfg['tag']}-" . substr($slug, 0, 40) . '@example.com',
        'attempt' => 0, 'checks' => ['count' => 0, 'ok' => 0, 'fail' => 0], 'last' => null, 'problems' => [],
    ];
}

foreach ($hosts as $name => $h) {
    say(hostLine($h) . ($h['read_only'] ? '' : '  -> ' . (whyNot($h, $state, time()) ?? 'ready')));
}
if (!writable($hosts)) {
    fail('every host is read_only: nothing can be deployed');
}
foreach (writable($hosts) as $name => $h) {
    [$st] = api($h, 'GET', '/projects/all', null, 30);
    if ($st !== 200) {
        fail("{$name}: {$h['api']} answered HTTP {$st}");
    }
}

if (isset($opt['dry-run'])) {
    foreach ($state['apps'] as $slug => $a) {
        say(sprintf('  %-10s %-28s %s', $a['phase'], $slug, $a['repo']));
    }
    exit(0);
}

adoptExisting($hosts, $state, $cfg['tag']);

$stop = false;
pcntl_async_signals(true);
pcntl_signal(SIGINT, function () use (&$stop) { $stop = true; });
pcntl_signal(SIGTERM, function () use (&$stop) { $stop = true; });

$lastSummary = 0;
while (!$stop) {
    $now = time();
    sampleHosts($hosts);
    startDeploys($hosts, $state, $cfg, $now);
    followDeploys($hosts, $state, $cfg);
    checkApps($hosts, $state, $cfg);
    runDeletes($hosts, $state);
    saveState($stateFile, $state);
    writeReport($stateDir, $state, $hosts);

    if ($now - $lastSummary >= 60) {
        $lastSummary = $now;
        say(summaryLine($state));
        foreach ($hosts as $h) {
            echo '  ' . hostLine($h) . '  -> ' . ($h['blocked'] ?? '') . "\n";
        }
    }
    if (allDone($state)) {
        say('every app is tested and deleted');
        break;
    }
    for ($i = 0; $i < $cfg['tick'] && !$stop; $i++) {
        sleep(1);
    }
}
saveState($stateFile, $state);
writeReport($stateDir, $state, $hosts);
say(summaryLine($state));
say("report: $stateDir/report.md");

// ---------------------------------------------------------------- the loop

function startDeploys(array &$hosts, array &$state, array $cfg, int $now): void
{
    $writable = count(writable($hosts));
    foreach ($hosts as $name => &$h) {
        $h['blocked'] = whyNot($h, $state, $now);
        if ($h['blocked'] !== null) {
            continue;
        }
        $slug = null;
        foreach ($state['apps'] as $s => $cand) {
            if ($cand['phase'] !== 'queued') {
                continue;
            }
            // A retry prefers another host; after five minutes any host will do.
            if (($cand['avoid'] ?? null) === $name && $writable > 1 && $now - ($cand['queued_at'] ?? 0) <= 300) {
                continue;
            }
            $slug = $s;
            break;
        }
        if ($slug === null) {
            $h['blocked'] = 'queue empty';
            continue;
        }
        $a = &$state['apps'][$slug];
        $project = substr(preg_replace('/[^a-z0-9]/', '', $slug), 0, 11) . substr(bin2hex(random_bytes(2)), 0, 4);
        $body = ['email' => $a['email'], 'name' => $project, 'git_repo' => $a['repo']];
        $mem = (int) ($h['memory_limit'] ?: $cfg['memory_limit']);
        if ($mem > 0) {
            $body['memory_limit'] = $mem;
        }
        $h['last_start'] = $now;
        $a['attempt']++;
        [$st, $res] = api($h, 'POST', '/projects', $body, 300);
        $data = $res['data'] ?? [];
        $task = $data['id'] ?? ($data['job']['id'] ?? null);
        if (!in_array($st, [200, 201, 202], true) || !$task) {
            $msg = is_array($res) ? (string) ($res['message'] ?? json_encode($res)) : (string) $res;
            failApp($a, "create HTTP $st: " . oneLine($msg), $st === 0 || $st >= 500, $name);
            event($a, "$name: create failed - HTTP $st " . oneLine($msg));
            unset($a);
            continue;
        }
        $a = array_merge($a, [
            'phase' => 'deploying', 'host' => $name, 'task' => $task,
            'project' => $data['details']['username'] ?? ($data['username'] ?? $project),
            'domain' => $data['details']['domain'] ?? ($data['domain'] ?? null),
            'deploy' => ['started' => $now, 'status' => 'queued'], 'log_after' => 0, 'log_tail' => [],
            'problems' => [], 'checks' => ['count' => 0, 'ok' => 0, 'fail' => 0], 'last' => null,
        ]);
        $h['blocked'] = 'just started';
        event($a, "$name: deploying {$a['project']} from {$a['repo']}");
        unset($a);
    }
}

function followDeploys(array $hosts, array &$state, array $cfg): void
{
    $reqs = [];
    foreach ($state['apps'] as $slug => $a) {
        if ($a['phase'] === 'deploying' && isset($hosts[$a['host']])) {
            $h = $hosts[$a['host']];
            $reqs["$slug|task"] = [$h, 'GET', "/tasks/{$a['task']}", null, 60];
            $reqs["$slug|logs"] = [$h, 'GET', "/tasks/{$a['task']}/logs?after_id={$a['log_after']}", null, 60];
        }
    }
    $res = apiMulti($reqs);
    foreach ($state['apps'] as $slug => &$a) {
        if (!isset($res["$slug|task"])) {
            continue;
        }
        [, $logs] = $res["$slug|logs"];
        foreach (($logs['data'] ?? []) as $line) {
            $text = (string) ($line['log'] ?? '');
            if (($line['level'] ?? 'dim') !== 'dim') {
                $a['log_tail'] = array_slice([...$a['log_tail'], $text], -40);
            }
            if (preg_match('/Detected project type: (.+)/', $text, $m)) {
                $a['deploy']['strategy'] = trim($m[1]);
            }
            if (preg_match('/Deploy failed:\s*(.+)/', $text, $m)) {
                $a['deploy']['reason'] = trim($m[1]);
            }
        }
        $a['log_after'] = $logs['meta']['next_after_id'] ?? $a['log_after'];

        [$st, $task] = $res["$slug|task"];
        $status = $task['data']['status'] ?? ($st === 404 ? 'missing' : 'unknown');
        $a['deploy']['status'] = $status;
        $elapsed = time() - $a['deploy']['started'];
        $h = $hosts[$a['host']];
        if ($status === 'completed') {
            $a['phase'] = 'running';
            $a['deploy']['seconds'] = $elapsed;
            $a['next_check'] = 0;
            event($a, "{$a['host']}: deployed in {$elapsed}s (" . ($a['deploy']['strategy'] ?? '?') . ')');
        } elseif (in_array($status, ['failed', 'cancelled', 'missing'], true)) {
            $reason = $a['deploy']['reason'] ?? oneLine((string) ($task['data']['error'] ?? '')) ?: $status;
            $a['deploy']['seconds'] = $elapsed;
            // A cancelled task says nothing about the app (worker killed, host restart).
            failApp($a, $reason, $status !== 'failed', $a['host']);
            event($a, "{$a['host']}: deploy $status after {$elapsed}s - $reason");
        } elseif ($elapsed > $cfg['timeout']) {
            api($h, 'POST', "/tasks/{$a['task']}/cancel", null, 60);
            failApp($a, "still {$status} after {$cfg['timeout']}s; cancelled", true, $a['host']);
            event($a, "{$a['host']}: deploy timed out after {$elapsed}s, cancelled");
        }
    }
}

function checkApps(array $hosts, array &$state, array $cfg): void
{
    $now = time();
    $reqs = [];
    foreach ($state['apps'] as $slug => $a) {
        if ($a['phase'] === 'running' && isset($hosts[$a['host']]) && $now >= ($a['next_check'] ?? 0)) {
            $h = $hosts[$a['host']];
            $u = $a['project'];
            $reqs["$slug|health"] = [$h, 'GET', "/projects/$u/app/health?timeout=5&attempts=2", null, 120];
            $reqs["$slug|containers"] = [$h, 'GET', "/projects/$u/containers", null, 60];
            if (empty($a['domain'])) {
                $reqs["$slug|project"] = [$h, 'GET', "/projects/$u", null, 60];
            }
        }
    }
    if (!$reqs) {
        return;
    }
    $res = apiMulti($reqs);
    foreach ($state['apps'] as $slug => &$a) {
        if (!isset($res["$slug|health"])) {
            continue;
        }
        if (isset($res["$slug|project"])) {
            $p = $res["$slug|project"][1]['data'] ?? [];
            $a['domain'] = $p['domain'] ?? ($p['details']['domain'] ?? null);
        }
        [$hst, $health] = $res["$slug|health"];
        [$cst, $containers] = $res["$slug|containers"];
        if ($hst === 404) {
            $a['project'] = null;
            failApp($a, 'project disappeared from the engine', false, $a['host']);
            event($a, "{$a['host']}: project {$a['project']} no longer exists");
            continue;
        }
        $last = evaluate($hst, $health['data'] ?? null, $cst, $containers['data'] ?? null);
        $last['at'] = date('Y-m-d H:i:s');
        $a['checks']['count']++;
        $a['checks'][$last['ok'] ? 'ok' : 'fail']++;
        $was = $a['last']['ok'] ?? null;
        $a['last'] = $last;
        $a['problems'] = $last['problems'];
        $a['next_check'] = $now + $cfg['interval'];
        if ($was !== $last['ok']) {
            event($a, "{$a['host']}: " . ($last['ok'] ? 'OK' : 'PROBLEM') . ' - ports ' . portsText($last['ports'])
                . ($last['problems'] ? ' - ' . implode('; ', $last['problems']) : ''));
        }
        if ($a['checks']['count'] >= $cfg['checks']) {
            $c = $a['checks'];
            $a['verdict'] = $c['fail'] === 0 ? 'OK' : ($c['ok'] > 0 ? 'FLAKY' : 'PROBLEM');
            $a['phase'] = 'deleting';
            $state['deletes'][] = ['host' => $a['host'], 'project' => $a['project'], 'slug' => $slug];
            event($a, "{$a['host']}: {$a['verdict']} after {$c['count']} checks ({$c['ok']} clean), deleting {$a['project']}");
        }
    }
}

/** Deletes queued projects, all hosts in parallel; an app is done once its project is gone. */
function runDeletes(array $hosts, array &$state): void
{
    $reqs = [];
    foreach ($state['deletes'] as $i => $d) {
        $reqs[$i] = [$hosts[$d['host']], 'DELETE', "/projects/{$d['project']}", null, 900];
    }
    if (!$reqs) {
        return;
    }
    $keep = [];
    foreach (apiMulti($reqs) as $i => [$st]) {
        $d = $state['deletes'][$i];
        $a = &$state['apps'][$d['slug']];
        if (in_array($st, [200, 202, 204, 404], true)) {
            if ($a['phase'] === 'deleting') {
                $a['phase'] = 'deleted';
            }
            event($a, "{$d['host']}: project {$d['project']} deleted (HTTP $st)");
        } else {
            $d['tries'] = ($d['tries'] ?? 0) + 1;
            // Give up after a few tries rather than block the run; the account stays for --cleanup.
            if ($d['tries'] < 5) {
                $keep[] = $d;
            } elseif ($a['phase'] === 'deleting') {
                $a['phase'] = 'deleted';
                $a['problems'][] = "delete failed (HTTP $st), remove with --cleanup";
            }
            event($a, "{$d['host']}: deleting {$d['project']} -> HTTP $st" . ($d['tries'] < 5 ? ', will retry' : ', giving up'));
        }
        unset($a);
    }
    $state['deletes'] = $keep;
}

/** One check: are the ports open, is it the app answering, what did the engine notice. */
function evaluate(int $hst, ?array $h, int $cst, ?array $containers): array
{
    $problems = [];
    $ports = [];
    if ($hst !== 200 || !is_array($h)) {
        $problems[] = "health check HTTP $hst";
    } else {
        foreach ($h['ports'] ?? [] as $p) {
            $ports[] = ['port' => $p['port'] ?? null, 'open' => ($p['status'] ?? '') === 'ok', 'http' => $p['http_code'] ?? null];
        }
        if (($h['healthy'] ?? null) === false) {
            $problems[] = 'no published port answers';
        } elseif (($h['healthy'] ?? null) === null) {
            $problems[] = 'publishes no port';
        }
        $serving = $h['serving'] ?? null;
        if ($serving !== null && $serving !== 'ok') {
            $problems[] = "serving: $serving";
        }
        $dom = $h['domain'] ?? null;
        if (is_array($dom) && !in_array($dom['verdict'] ?? 'ok', ['ok', 'skipped'], true)) {
            $problems[] = "domain {$dom['verdict']}" . (isset($dom['http_code']) ? " (HTTP {$dom['http_code']})" : '');
        }
        foreach ($h['checks'] ?? [] as $c) {
            if (($c['status'] ?? '') === 'fail') {
                $problems[] = ($c['severity'] ?? 'error') . ': ' . ($c['title'] ?? $c['id'] ?? '?');
            }
        }
        if (!empty($h['error'])) {
            $problems[] = oneLine((string) $h['error']);
        }
    }
    $svc = [];
    foreach (is_array($containers) ? $containers : [] as $c) {
        $svc[] = ($c['service'] ?? '?') . ':' . ($c['status'] ?? '?');
        if (($c['status'] ?? '') === 'restarting') {
            $problems[] = "container {$c['service']} restarting";
        }
    }
    if ($cst !== 200) {
        $problems[] = "container list HTTP $cst";
    }
    $problems = array_values(array_unique($problems));

    return [
        'ok' => !$problems,
        'ports' => $ports,
        'serving' => $h['serving'] ?? null,
        'domain' => $h['domain']['verdict'] ?? null,
        'containers' => $svc,
        'problems' => $problems,
    ];
}

function failApp(array &$a, string $reason, bool $retryable, ?string $host): void
{
    global $state;
    // The rollback normally removes a failed deploy's account; delete anyway, a 404 is fine.
    if (!empty($a['project']) && $host !== null) {
        $state['deletes'][] = ['host' => $host, 'project' => $a['project'], 'slug' => $a['slug']];
        $a['project'] = null;
    }
    // Retry once when the failure is about the host or the engine, not the app.
    if ($retryable && $a['attempt'] < 2) {
        $a['phase'] = 'queued';
        $a['avoid'] = $host;
        $a['queued_at'] = time();
        $a['retry_reason'] = $reason;

        return;
    }
    $a['phase'] = 'failed';
    $a['verdict'] = 'DEPLOY FAILED';
    $a['problems'] = [$reason];
}

function adoptExisting(array $hosts, array &$state, string $tag): void
{
    $byEmail = [];
    foreach (writable($hosts) as $name => $h) {
        foreach (taggedAccounts($h, $tag) as $p) {
            $byEmail[$p['email']] = [$name, $p];
        }
    }
    foreach ($state['apps'] as &$a) {
        if ($a['phase'] === 'queued' && isset($byEmail[$a['email']])) {
            [$name, $p] = $byEmail[$a['email']];
            $a = array_merge($a, ['phase' => 'running', 'host' => $name, 'project' => $p['username'],
                'domain' => $p['domain'] ?? null, 'next_check' => 0]);
            event($a, "$name: adopted existing project {$p['username']}");
        }
    }
}

// ---------------------------------------------------------------- hosts

function loadHosts(string $file): array
{
    $hosts = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: fail("cannot read $file") as $line) {
        $line = trim(preg_replace('/#.*/', '', $line));
        if ($line === '') {
            continue;
        }
        $parts = preg_split('/\s+/', $line);
        if (count($parts) < 2) {
            fail("hosts line needs a name and an ssh target: $line");
        }
        $h = HOST_DEFAULTS + ['name' => $parts[0], 'ssh' => $parts[1], 'api' => null, 'token' => ''];
        foreach (array_slice($parts, 2) as $kv) {
            [$k, $v] = array_pad(explode('=', $kv, 2), 2, null);
            if (!array_key_exists($k, $h)) {
                fail("unknown host option '$k' on: $line");
            }
            $h[$k] = $v === null ? true : (is_numeric($v) ? $v + 0 : $v);
        }
        $h['api'] = rtrim($h['api'] ?: 'https://' . preg_replace('/^.*@/', '', $h['ssh']) . ':2011/api', '/');
        $h += ['sample' => null, 'workers_seen' => [], 'last_start' => 0, 'fail_streak' => 0, 'blocked' => null];
        $hosts[$h['name']] = $h;
    }

    return $hosts ?: fail("no hosts in $file");
}

/** Samples every host at once: one ssh per host, started together. */
function sampleHosts(array &$hosts): void
{
    $procs = [];
    foreach ($hosts as $name => $h) {
        $p = proc_open(array_merge(['timeout', '25'], sshArgv($h['ssh']), ['bash', '-s']),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], SAMPLE_SCRIPT);
        fclose($pipes[0]);
        $procs[$name] = [$p, $pipes];
    }
    foreach ($procs as $name => [$p, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($p);
        $s = [];
        foreach (explode("\n", (string) $out) as $line) {
            if (preg_match('/^(\w+)=([\d.]+)$/', trim($line), $m)) {
                $s[$m[1]] = (float) $m[2];
            }
        }
        $h = &$hosts[$name];
        if ($code !== 0 || !isset($s['cpus'], $s['mem_avail_kb'])) {
            $h['fail_streak']++;
            $h['sample_error'] = oneLine((string) $err) ?: "ssh exit $code";
        } else {
            $h['sample'] = $s;
            $h['sample_at'] = time();
            $h['fail_streak'] = 0;
            // Workers recycle (--max-jobs/--max-time) and briefly read 0: keep the recent maximum.
            $h['workers_seen'] = array_slice([...$h['workers_seen'], (int) ($s['workers'] ?? 0)], -10);
        }
        unset($h);
    }
}

/** Why the next app may not start on this host now, or null when it may. */
function whyNot(array $h, array $state, int $now): ?string
{
    if ($h['read_only']) {
        return 'read-only';
    }
    $s = $h['sample'];
    if ($s === null || $h['fail_streak'] >= 3) {
        return 'unreachable';
    }
    $deploying = $apps = $warming = 0;
    foreach ($state['apps'] as $a) {
        if (($a['host'] ?? null) !== $h['name']) {
            continue;
        }
        $apps += in_array($a['phase'], ['deploying', 'running', 'deleting'], true) ? 1 : 0;
        $deploying += $a['phase'] === 'deploying' ? 1 : 0;
        // A deploy's memory shows up late (the build), so reserve it until it has warmed up.
        $warming += in_array($a['phase'], ['deploying', 'running'], true)
            && $now - ($a['deploy']['started'] ?? 0) < $h['warmup_seconds'] ? 1 : 0;
    }
    $cap = deployCap($h);
    if ($deploying >= $cap) {
        return "deploy slots ($deploying/$cap)";
    }
    if ($h['max_apps'] && $apps >= $h['max_apps']) {
        return "max_apps ($apps)";
    }
    if ($s['load1'] / $s['cpus'] >= $h['target_load']) {
        return sprintf('cpu (load %.1f)', $s['load1']);
    }
    $free = $s['mem_avail_kb'] / 1024 - $warming * $h['reserve_mem_mb'];
    if ($free < $h['min_mem_mb'] + $h['reserve_mem_mb']) {
        return sprintf('memory (%.0f MB free after reserves)', $free);
    }
    if (($s['psi_mem'] ?? 0) > $h['max_mem_pressure']) {
        return 'memory pressure';
    }
    if (($s['disk_free_kb'] ?? 0) / 1048576 < $h['min_disk_gb']) {
        return 'disk';
    }
    if ($now - $h['last_start'] < $h['ramp_seconds']) {
        return 'ramp';
    }

    return null;
}

/** More deploys than queue workers only wait inside the engine, on the deploy's own timeout. */
function deployCap(array $h): int
{
    return (int) ($h['max_deploys'] ?: max(1, max($h['workers_seen'] ?: [1])));
}

function hostLine(array $h): string
{
    $s = $h['sample'];
    if ($s === null) {
        return sprintf('%-10s no sample (%s)', $h['name'], $h['sample_error'] ?? '?');
    }

    return sprintf('%-10s %s  load %.1f/%d  mem %.1f/%.1fG free  disk %.0fG  psi %.0f%%',
        $h['name'], $h['read_only'] ? 'ro' : 'cap ' . deployCap($h), $s['load1'], (int) $s['cpus'],
        $s['mem_avail_kb'] / 1048576, $s['mem_total_kb'] / 1048576, ($s['disk_free_kb'] ?? 0) / 1048576, $s['psi_mem'] ?? 0);
}

function writable(array $hosts): array
{
    return array_filter($hosts, fn ($h) => !$h['read_only']);
}

function taggedAccounts(array $h, string $tag): array
{
    [$st, $res] = api($h, 'GET', '/projects/all', null, 60);
    $pat = '/^' . preg_quote($tag, '/') . '-.*@example\.com$/';

    return array_values(array_filter($res['data'] ?? [],
        fn ($p) => preg_match($pat, (string) ($p['email'] ?? '')) && !empty($p['username'])));
}

// ---------------------------------------------------------------- apps, state, report

function loadApps(string $file): array
{
    $apps = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: fail("cannot read $file") as $line) {
        $line = trim(preg_replace('/(^|\s)#.*/', '', $line));
        if ($line === '') {
            continue;
        }
        [$repo, $name] = array_pad(preg_split('/\s+/', $line, 2), 2, null);
        $name ??= implode('-', array_slice(explode('/', preg_replace('#(\.git)?/*$#', '', $repo)), -2));
        $slug = preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?: 'app';
        $apps[$slug] ??= ['slug' => $slug, 'name' => $name, 'repo' => $repo];
    }

    return $apps;
}

function saveState(string $file, array $state): void
{
    file_put_contents("$file.tmp", json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    rename("$file.tmp", $file);
}

function writeReport(string $dir, array $state, array $hosts): void
{
    $rows = ["# App fleet report", '', 'Updated ' . date('Y-m-d H:i:s') . '. ' . summaryLine($state), '',
        '## Hosts', '', '```'];
    foreach ($hosts as $h) {
        $rows[] = hostLine($h) . '  -> ' . ($h['blocked'] ?? '');
    }
    $rows = [...$rows, '```', '', '## Apps', '',
        '| App | Host | Status | Deployed | Strategy | Ports | Serving | Domain | Checks ok/all | Problems |',
        '|---|---|---|---|---|---|---|---|---|---|'];
    foreach ($state['apps'] as $a) {
        $l = $a['last'] ?? [];
        $status = $a['verdict'] ?? ($a['phase'] === 'running' && isset($l['ok']) ? $a['phase'] . ($l['ok'] ? ' (ok)' : ' (problem)') : $a['phase']);
        $rows[] = '| ' . implode(' | ', array_map(fn ($c) => str_replace('|', '\|', (string) $c), [
            "[{$a['name']}]({$a['repo']})",
            $a['host'] ?? '-',
            $status,
            isset($a['deploy']['seconds']) ? $a['deploy']['seconds'] . 's' : '-',
            $a['deploy']['strategy'] ?? '-',
            portsText($l['ports'] ?? []),
            $l['serving'] ?? '-',
            ($a['domain'] ?? '-') . (isset($l['domain']) ? " ({$l['domain']})" : ''),
            "{$a['checks']['ok']}/{$a['checks']['count']}",
            implode('; ', $a['problems'] ?? []) ?: '-',
        ])) . ' |';
    }
    $failed = array_filter($state['apps'], fn ($a) => $a['phase'] === 'failed' && !empty($a['log_tail']));
    if ($failed) {
        $rows = [...$rows, '', '## Failed deploys, last log lines', ''];
        foreach ($failed as $a) {
            $rows = [...$rows, "### {$a['name']}", '', '```', ...array_slice($a['log_tail'], -15), '```', ''];
        }
    }
    file_put_contents("$dir/report.md", implode("\n", $rows) . "\n");
    file_put_contents("$dir/report.json", json_encode(array_values(array_map(
        fn ($a) => array_diff_key($a, ['log_tail' => 1, 'log_after' => 1]), $state['apps'])),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function summaryLine(array $state): string
{
    $n = ['queued' => 0, 'deploying' => 0, 'checking' => 0];
    $v = ['OK' => 0, 'FLAKY' => 0, 'PROBLEM' => 0, 'DEPLOY FAILED' => 0];
    foreach ($state['apps'] as $a) {
        match ($a['phase']) {
            'queued' => $n['queued']++,
            'deploying' => $n['deploying']++,
            'running', 'deleting' => $n['checking']++,
            default => isset($a['verdict']) ? $v[$a['verdict']]++ : null,
        };
    }

    return "queued {$n['queued']}, deploying {$n['deploying']}, checking {$n['checking']} | "
        . implode(', ', array_map(fn ($k, $c) => "$k $c", array_keys($v), $v));
}

function allDone(array $state): bool
{
    foreach ($state['apps'] as $a) {
        if (!in_array($a['phase'], ['failed', 'deleted'], true)) {
            return false;
        }
    }

    return !$state['deletes'];
}

function portsText(array $ports): string
{
    return implode(' ', array_map(fn ($p) => $p['port'] . ($p['open'] ? ' open' : ' closed')
        . ($p['http'] ? "({$p['http']})" : ''), $ports)) ?: '-';
}

function event(array &$a, string $msg): void
{
    global $stateDir;
    $line = date('[H:i:s] ') . str_pad($a['slug'], 20) . ' ' . $msg;
    echo $line . "\n";
    if (isset($stateDir)) {
        file_put_contents("$stateDir/events.log", $line . "\n", FILE_APPEND);
    }
    $a['events'] = array_slice([...($a['events'] ?? []), date('Y-m-d H:i:s ') . $msg], -20);
}

// ---------------------------------------------------------------- transport

function sshArgv(string $target): array
{
    // ControlMaster: one connection per host, reused by every sample.
    return ['ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', '-o', 'ServerAliveInterval=15',
        '-o', 'ControlMaster=auto', '-o', 'ControlPath=' . getenv('HOME') . '/.ssh/app-fleet-%C',
        '-o', 'ControlPersist=10m', $target];
}

function ssh(string $target, string $cmd): array
{
    $p = proc_open(array_merge(sshArgv($target), [$cmd]), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);

    return [proc_close($p), (string) $out];
}

function api(array $h, string $method, string $path, ?array $body = null, int $timeout = 120): array
{
    return apiMulti(['x' => [$h, $method, $path, $body, $timeout]])['x'];
}

/** Runs every request concurrently; returns key => [http status (0 = no answer), decoded body]. */
function apiMulti(array $reqs): array
{
    $mh = curl_multi_init();
    $handles = [];
    foreach ($reqs as $key => [$h, $method, $path, $body, $timeout]) {
        $ch = curl_init($h['api'] . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $h['token'], 'Accept: application/json',
                'Content-Type: application/json'],
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 1.0);
        }
    } while ($active && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $key => $ch) {
        $raw = (string) curl_multi_getcontent($ch);
        $out[$key] = [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), json_decode($raw, true) ?? $raw];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    return $out;
}

function oneLine(string $s): string
{
    return mb_substr(trim(preg_replace('/\s+/', ' ', strtok($s, "\n") ?: '')), 0, 200);
}

function say(string $msg): void
{
    echo date('[H:i:s] ') . $msg . "\n";
}

function fail(string $msg): never
{
    fwrite(STDERR, $msg . "\n");
    exit(1);
}
