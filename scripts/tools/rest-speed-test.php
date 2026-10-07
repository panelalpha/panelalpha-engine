#!/usr/bin/env php
<?php

/**
 * Deploy speed on a real host, measured entirely over the REST API.
 *
 * Seven applications across three runtimes — PHP (Matomo on 8.1, Laravel and
 * Grav on 8.3), Node (Next.js, NestJS), Python (Django, FastAPI) — each
 * deployed cold, rebuilt warm and restarted, with every stage of every run
 * reported. Nothing here touches the host by SSH or artisan: a project is
 * created with `POST /projects`, watched
 * with `GET /projects/{u}/deploy-log`, verified with `GET /projects/{u}/app/health`
 * and removed with `DELETE /projects/{u}`. What the engine cannot tell a REST
 * client is therefore not measured, which is the point — this is the number a
 * caller of the API actually experiences.
 *
 * The rule from AGENTS.md holds: a total is not a result. Every run is
 * reported per stage (`preparing`, `cloning`, `running`), per phase (detect,
 * image transfer, build, start-to-answer) and per build layer, because those
 * four have four different fixes.
 *
 * Usage:
 *   scripts/tools/rest-speed-test.php --url=https://HOST:2011/api --token=TOKEN [flags]
 *
 *   --apps=a,b          Subset by fixture name (matomo,laravel,grav,nextjs,nestjs,express,go,java,django,fastapi)
 *   --modes=…           Which of the four measurements to run, in this order:
 *                         noprewarm  1. start with NO host prewarm — the shared base image is
 *                                       dropped from the host first, so the deploy has to build it
 *                         cold       2. start with prewarm — fresh account, base image on the host
 *                         rebuild    3. rebuild in place — prewarm plus every cache
 *                         restart    4. container down/up: no clone, no install, no build
 *                       (default: cold,rebuild,restart)
 *   --unprewarm=TARGET  ssh target (root@host) used ONLY by mode 1, to drop the host's base image.
 *                       The REST API cannot change host image state; no other mode needs it.
 *   --unprewarm-deep    …and prune the host's BuildKit cache, so mode 1 measures a base image
 *                       built from source rather than reassembled from cached layers. Without it
 *                       the second app tested on a given runtime version rebuilds in seconds and
 *                       the report says so instead of pretending otherwise.
 *   --preflight-only    Inspect every repository and stop. Deploys nothing.
 *   --keep              Do not delete the projects afterwards
 *   --recreate          Delete a project of the same name if one already exists
 *   --strict-runtime    Fail preflight when an app resolves to a toolchain version other than the one
 *                       the fixture declares (php 8.1 for Matomo, php 8.3 for Laravel and Grav, ...)
 *   --min-warm-ratio=N  Warm cache hit ratio below this fails the run (default 0.5)
 *   --timeout=N         Seconds per deploy (default 1800)
 *   --prefix=STR        Username prefix, 1-4 lowercase letters (default "spd")
 *   --domain-suffix=D   Give each project <username>.D instead of the generated <username>.local
 *   --json=FILE         Write the full result document (every timing block, verbatim)
 *   --verbose           Stream the deploy log as it happens
 *
 * Environment: PA_API_URL, PA_API_TOKEN stand in for --url / --token.
 *
 * Exit code is 0 only when every requested app deployed, detected as expected,
 * answered its health probe, and rebuilt with a cache hit ratio at or above
 * --min-warm-ratio.
 *
 * See AGENTS.md §9 for what each number means and what
 * to do about it when it moves.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This is a CLI script.\n");
    exit(2);
}
foreach (['curl', 'json'] as $ext) {
    if (!extension_loaded($ext)) {
        fwrite(STDERR, "PHP extension '{$ext}' is required.\n");
        exit(2);
    }
}

/**
 * The scenario.
 *
 * Every expectation below was resolved with the engine's own code against a
 * clone of the repository, not guessed — re-derive any line with:
 *
 *   php -r 'require "core/vendor/autoload.php";
 *           print_r(App\Lib\Deploy\Inspect\AppInspector::inspect($argv[1])["application"]);' <dir>
 *
 * `runtime` is written as "<id> <version>" and is checked against the
 * toolchain the engine reports for the repository, so a base-image change or
 * an upstream bump to composer.json shows up as a preflight warning rather
 * than as an unexplained jump in build time.
 */
const FIXTURES = [
    [
        'name' => 'matomo',
        'user' => 'matomo',
        'repo' => 'https://github.com/matomo-org/matomo',
        'branch' => '6.x-dev',
        'strategy' => 'php',
        // The PHP 8.1 half of the PHP set, and not by accident: Matomo
        // declares `>=8.1.0`, and the engine takes the LOWEST minor that
        // satisfies every constraint, so this is php 8.1 whatever anyone
        // wishes. Kept as the 8.1 datapoint rather than fought with — 8.1 is
        // also the lowest-priority base image in the host's prewarm plan, so
        // it is the minor most likely to show a cold base-image cost.
        // MATOMO_REPO/MATOMO_BRANCH point it at a fork if you want it on 8.3.
        'runtime' => 'php 8.1',
        'env' => [],
        'repo_env' => 'MATOMO_REPO',
        'branch_env' => 'MATOMO_BRANCH',
        'note' => 'MySQL is provisioned by the app manifest (database: mysql).',
    ],
    [
        'name' => 'laravel',
        'user' => 'laravel',
        'repo' => 'https://github.com/laravel/laravel',
        // 12.x requires ^8.2 and would deploy on php 8.2. The 13.x branch
        // requires ^8.3, which is what this scenario is asked to measure.
        'branch' => '13.x',
        'strategy' => 'laravel',
        'runtime' => 'php 8.3',
        'env' => [],
        'note' => '',
    ],
    [
        'name' => 'grav',
        'user' => 'grav',
        'repo' => 'https://github.com/getgrav/grav',
        // Grav develops on `develop`; it is the repository's default branch,
        // named here so the fixture cannot silently change branch under us.
        'branch' => 'develop',
        'strategy' => 'php',
        // ^8.3 in composer.json, and composer.lock narrows nothing below it:
        // the second PHP 8.3 application, on the plain `php` platform rather
        // than a framework, so 8.3 is covered by two different code paths.
        'runtime' => 'php 8.3',
        'env' => [],
        // The stock recipe deploys Grav and the site answers HTTP 500 — `Theme
        // 'quark2' does not exist`. Grav's git repository ships `user/themes`
        // and `user/plugins` EMPTY; the theme and the error/problems plugins
        // are fetched by `bin/grav install`, which no platform recipe knows to
        // run. Naming the build stage here replaces it, so composer install has
        // to be restated: a stage given in the request takes over that stage
        // entirely.
        'stages' => [
            'build' => [
                [
                    'id' => 'composer-install',
                    'run' => 'composer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader',
                    'role' => 'dependencies',
                ],
                [
                    'id' => 'grav-install',
                    'run' => 'bin/grav install',
                    'optional' => true,
                ],
            ],
        ],
        'note' => 'Flat-file CMS: no database, no external service. Also fixture bphp2 in benchmark-deploys.sh.',
    ],
    [
        'name' => 'nextjs',
        'user' => 'nextjs',
        'repo' => 'https://github.com/vercel/nextjs-portfolio-starter',
        'branch' => 'main',
        'strategy' => 'nextjs',
        'runtime' => 'node 20',
        'env' => [],
        // Deliberately the heavy half of the Node pair: yarn install plus a
        // real `next build`. A pair of trivial apps measures the engine's
        // fixed costs twice and its build path not at all.
        'note' => 'yarn --frozen-lockfile + next build; needs no external service.',
    ],
    [
        'name' => 'nestjs',
        'user' => 'nestjs',
        'repo' => 'https://github.com/nestjs/typescript-starter',
        'branch' => 'master',
        'strategy' => 'nestjs',
        'runtime' => 'node 20',
        'env' => [],
        'note' => 'npm ci + tsc build.',
    ],
    [
        'name' => 'express',
        'user' => 'express',
        'repo' => 'https://github.com/heroku/node-js-getting-started',
        'branch' => 'main',
        'strategy' => 'express',
        'runtime' => 'node 22',
        'env' => [],
        // The light end of the Node set, and the one with no build step at
        // all: its manifest's build command is gated on the project declaring
        // a `build` script and this repository declares none. That makes it
        // the case that proves a mounted deploy does not depend on a build
        // having produced an output directory.
        'note' => 'npm install only, no build; engines.node pins 22.',
    ],
    [
        'name' => 'go',
        'user' => 'go',
        'repo' => 'https://github.com/heroku/go-getting-started',
        'branch' => 'main',
        'strategy' => 'go',
        'runtime' => 'go 1.27',
        'env' => [],
        // Compiles to `./app` inside the project, which is why Go needed
        // nothing language-specific to run from a mounted directory.
        'note' => 'go build -o app ./...; serves on the platform port.',
    ],
    [
        'name' => 'java',
        'user' => 'java',
        'repo' => 'https://github.com/spring-projects/spring-petclinic',
        'branch' => 'main',
        'strategy' => 'java',
        'runtime' => 'java 17',
        'env' => [],
        // The heavyweight of the set: `mvn package` was 106.7s of a 124.3s
        // build in the older battery. Its jar lands in target/, and its start
        // command globs for it -- which is why the mounted service runs the
        // command through a shell.
        'note' => 'mvn -B -DskipTests package; java -jar target/*.jar.',
    ],
    [
        'name' => 'django',
        'user' => 'django',
        'repo' => 'https://github.com/heroku/python-getting-started',
        'branch' => 'main',
        'strategy' => 'django',
        'runtime' => 'python 3.12',
        // Without this the app serves a 500: its STORAGES uses whitenoise's
        // manifest storage, and `{% static %}` on the index page has no
        // manifest until collectstatic runs. DEBUG resolves the static URL
        // unhashed instead. ALLOWED_HOSTS already contains 127.0.0.1, which
        // is the Host the engine's health probe sends.
        'env' => ['ENVIRONMENT' => 'development'],
        'note' => 'sqlite, no external service.',
    ],
    [
        'name' => 'fastapi',
        'user' => 'fastapi',
        'repo' => 'https://github.com/Azure-Samples/msdocs-python-fastapi-webapp-quickstart',
        'branch' => 'main',
        'strategy' => 'python',
        'runtime' => 'python 3.12',
        // main.py ends in uvicorn.run(host='0.0.0.0', port=8000), which is the
        // port the python platform manifest publishes. An app that binds
        // 127.0.0.1 or another port would deploy and never answer.
        'env' => [],
        // The stock recipe deploys it and every request is a 500 — `TypeError:
        // unhashable type: 'dict'` out of Jinja. The repository calls
        // `templates.TemplateResponse('index.html', {...})`, the argument order
        // Starlette 0.47 removed, and its requirements.txt pins nothing, so a
        // fresh pip install gets the version that refuses it. Upstream rot, not
        // an engine fault — constrained here rather than forked, and the
        // constraint is visible in the report.
        'stages' => [
            'build' => [
                [
                    'id' => 'pip-install',
                    'run' => 'python -m venv .venv && .venv/bin/pip install -r requirements.txt "starlette<0.47"',
                    'role' => 'dependencies',
                ],
            ],
        ],
        'note' => 'uvicorn on 0.0.0.0:8000, matching the platform port.',
    ],
];

/**
 * The four measurements, and what each one is actually asking.
 *
 * They differ by exactly one thing each, which is the only way the numbers
 * mean anything: mode 2 minus mode 1 is what prewarming the host saves, mode 3
 * minus mode 2 is what the caches save, and mode 4 is the floor — what a
 * container costs to start when nothing has to be fetched, installed or built.
 *
 * @var array<string, array{title: string, what: string, has: string, lacks: string}>
 */
const MODES = [
    'noprewarm' => [
        'title' => '1. START WITHOUT PREWARM',
        'what' => 'first deploy of a new project on a host that has no shared base image for this runtime',
        'has' => 'nothing',
        'lacks' => 'account, container, clone, dependencies, image — and the host base image, which this deploy must build',
    ],
    'cold' => [
        'title' => '2. START WITH PREWARM',
        'what' => 'first deploy of a new project on a prewarmed host',
        'has' => 'the shared base image, already built on the host',
        'lacks' => 'account, container, clone, dependencies, built image',
    ],
    'rebuild' => [
        'title' => '3. REBUILD',
        'what' => 'redeploying the same project in place — the production update path',
        'has' => 'account, container, base image in the account, BuildKit layer cache, host build caches',
        'lacks' => 'nothing except the new source, which it re-clones',
    ],
    'restart' => [
        'title' => '4. RESTART',
        'what' => 'stopping and starting the container that is already built',
        'has' => 'everything',
        'lacks' => 'nothing — no clone, no dependency install, no build',
    ],
];

/** Line offset large enough that a poll never carries the log body back. */
const NO_LINES_OFFSET = 1000000000;

// ---------------------------------------------------------------- arguments

$opts = [
    'url' => getenv('PA_API_URL') ?: '',
    'token' => getenv('PA_API_TOKEN') ?: '',
    'apps' => '',
    'modes' => 'cold,rebuild,restart',
    'unprewarm' => '',
    'timeout' => 1800,
    'min-warm-ratio' => 0.5,
    'prefix' => 'spd',
    'domain-suffix' => '',
    'json' => '',
];
$flags = ['keep' => false, 'recreate' => false, 'strict-runtime' => false, 'unprewarm-deep' => false, 'verbose' => false, 'preflight-only' => false];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '-h' || $arg === '--help') {
        $doc = file_get_contents(__FILE__);
        preg_match('#/\*\*(.+?)\*/#s', (string) $doc, $m);
        echo preg_replace('/^\s*\*ma?/m', '', preg_replace('/^\s*\* ?/m', '', $m[1] ?? '')), "\n";
        exit(0);
    }
    if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) && array_key_exists($m[1], $opts)) {
        $opts[$m[1]] = $m[2];
        continue;
    }
    if (preg_match('/^--([a-z-]+)$/', $arg, $m) && array_key_exists($m[1], $flags)) {
        $flags[$m[1]] = true;
        continue;
    }
    fwrite(STDERR, "unknown flag: {$arg}\n");
    exit(2);
}

if ($opts['url'] === '' || $opts['token'] === '') {
    fwrite(STDERR, "--url and --token are required (or PA_API_URL / PA_API_TOKEN).\n");
    exit(2);
}
if (!preg_match('/^[a-z]{1,4}$/', (string) $opts['prefix'])) {
    fwrite(STDERR, "--prefix must be 1-4 lowercase letters.\n");
    exit(2);
}

$baseUrl = rtrim((string) $opts['url'], '/');
$token = (string) $opts['token'];
$timeout = max(60, (int) $opts['timeout']);
$minWarmRatio = (float) $opts['min-warm-ratio'];
$modes = array_values(array_filter(array_map('trim', explode(',', (string) $opts['modes']))));
foreach ($modes as $mode) {
    if (!isset(MODES[$mode])) {
        fwrite(STDERR, "unknown mode '{$mode}'. Known: " . implode(',', array_keys(MODES)) . "\n");
        exit(2);
    }
}
if (in_array('noprewarm', $modes, true) && $opts['unprewarm'] === '') {
    fwrite(
        STDERR,
        "--modes=noprewarm needs --unprewarm=<ssh target>: removing the host's shared base image\n"
        . "is not something the REST API can do. See AGENTS.md §9.\n"
    );
    exit(2);
}
$only = array_values(array_filter(array_map('trim', explode(',', (string) $opts['apps']))));

$fixtures = [];
foreach (FIXTURES as $fixture) {
    if ($only !== [] && !in_array($fixture['name'], $only, true)) {
        continue;
    }
    if (isset($fixture['repo_env']) && ($override = getenv($fixture['repo_env'])) !== false && $override !== '') {
        $fixture['repo'] = $override;
        $fixture['branch'] = '';
    }
    if (isset($fixture['branch_env']) && ($override = getenv($fixture['branch_env'])) !== false && $override !== '') {
        $fixture['branch'] = $override;
    }
    $fixture['username'] = $opts['prefix'] . $fixture['user'];
    $fixtures[] = $fixture;
}
if ($fixtures === []) {
    fwrite(STDERR, "No fixtures selected. Known: " . implode(',', array_column(FIXTURES, 'name')) . "\n");
    exit(2);
}

// ------------------------------------------------------------------- client

/**
 * @param array<string, mixed>|null $body
 * @return array{status: int, body: mixed, seconds: float, error: ?string}
 */
function api(string $method, string $path, ?array $body = null, int $timeoutSec = 120): array
{
    global $baseUrl, $token;

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, request_options($method, $body, $timeoutSec));
    $started = microtime(true);
    $raw = curl_exec($ch);
    $seconds = round(microtime(true) - $started, 3);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'status' => $status,
        'body' => is_string($raw) ? (json_decode($raw, true) ?? $raw) : null,
        'seconds' => $seconds,
        'error' => $error,
    ];
}

/**
 * @param array<string, mixed>|null $body
 * @return array<int, mixed>
 */
function request_options(string $method, ?array $body, int $timeoutSec): array
{
    global $token;

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 15,
        // A speed test that dies on a self-signed certificate measures
        // nothing. The engine is addressed by IP as often as by name.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ];
    if ($body !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_SLASHES);
    }

    return $options;
}

/**
 * Start a request without waiting for it, so the deploy log can be polled
 * while the deploy that writes it is still running. `POST /projects` does not
 * return until the application answers; on a cold Matomo that is minutes.
 *
 * @param array<string, mixed>|null $body
 * @return array{multi: CurlMultiHandle, handle: CurlHandle, started: float}
 */
function start_async(string $method, string $path, ?array $body, int $timeoutSec): array
{
    global $baseUrl;

    $ch = curl_init($baseUrl . $path);
    curl_setopt_array($ch, request_options($method, $body, $timeoutSec));
    $mh = curl_multi_init();
    curl_multi_add_handle($mh, $ch);
    curl_multi_exec($mh, $running);

    return ['multi' => $mh, 'handle' => $ch, 'started' => microtime(true)];
}

/**
 * @param array{multi: CurlMultiHandle, handle: CurlHandle, started: float} $call
 * @return array{done: bool, status: int, body: mixed, error: ?string}
 */
function poll_async(array $call): array
{
    $running = 0;
    curl_multi_exec($call['multi'], $running);
    if ($running > 0) {
        return ['done' => false, 'status' => 0, 'body' => null, 'error' => null];
    }

    $raw = curl_multi_getcontent($call['handle']);
    $status = (int) curl_getinfo($call['handle'], CURLINFO_RESPONSE_CODE);
    $error = curl_errno($call['handle']) !== 0 ? curl_error($call['handle']) : null;

    return [
        'done' => true,
        'status' => $status,
        'body' => is_string($raw) ? (json_decode($raw, true) ?? $raw) : null,
        'error' => $error,
    ];
}

/** @param array{multi: CurlMultiHandle, handle: CurlHandle, started: float} $call */
function finish_async(array $call): void
{
    curl_multi_remove_handle($call['multi'], $call['handle']);
    curl_close($call['handle']);
    curl_multi_close($call['multi']);
}

/** The payload of a `{"data": ...}` envelope, or the body itself when there is none. */
function data(mixed $body): mixed
{
    return is_array($body) && array_key_exists('data', $body) ? $body['data'] : $body;
}

function message(mixed $body): string
{
    if (is_array($body)) {
        if (isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        return substr(json_encode($body, JSON_UNESCAPED_SLASHES) ?: '', 0, 300);
    }

    return substr(is_string($body) ? $body : '', 0, 300);
}

// ------------------------------------------------------------- the measuring

/**
 * Watch one deploy from the outside: the request that started it, the log it
 * writes while it runs, and the timings the engine computed once it stopped.
 *
 * Two clocks are kept on purpose. `client_seconds` is what the API caller
 * waited for, including request queueing and the engine's own bookkeeping;
 * `server.total_seconds` is what the deploy itself took. When they disagree
 * by more than a second or two, the gap is the engine, not the build.
 *
 * @param array{multi: CurlMultiHandle, handle: CurlHandle, started: float} $call
 * @return array<string, mixed>
 */
function watch_deploy(string $username, array $call, ?string $previousLogId, int $timeoutSec): array
{
    global $flags;

    $observed = [];
    $seenStage = [];
    $firstLogAt = null;
    $offset = $flags['verbose'] ? 0 : NO_LINES_OFFSET;
    $response = null;
    $logId = null;
    $finalLog = null;
    $timedOut = false;

    while (true) {
        $elapsed = microtime(true) - $call['started'];

        if ($response === null) {
            $poll = poll_async($call);
            if ($poll['done']) {
                $response = $poll;
            }
        }

        $log = api('GET', "/projects/{$username}/deploy-log?offset={$offset}", null, 30);
        $entry = is_array($log['body']) ? data($log['body']) : null;

        if (is_array($entry) && ($entry['status'] ?? 'none') !== 'none') {
            $id = $entry['id'] ?? null;
            // A rebuild writes a new log next to the old one. Until the id
            // changes, what is being read is the previous run's result.
            $isThisRun = $previousLogId === null || $id === null || $id !== $previousLogId;

            if ($isThisRun) {
                $logId ??= $id;
                $firstLogAt ??= round($elapsed, 2);
                $stage = $entry['stage'] ?? null;
                if (is_string($stage) && !isset($seenStage[$stage])) {
                    $seenStage[$stage] = true;
                    $observed[] = ['stage' => $stage, 'at' => round($elapsed, 2)];
                }
                if ($flags['verbose'] && isset($entry['lines']) && is_array($entry['lines'])) {
                    foreach ($entry['lines'] as $line) {
                        if (($line['level'] ?? 'dim') !== 'dim') {
                            printf("      %6.1fs  %s\n", $elapsed, (string) ($line['msg'] ?? ''));
                        }
                    }
                    $offset = (int) ($entry['next_offset'] ?? $offset);
                }
                $finalLog = $entry;
                if (($entry['finished_at'] ?? null) !== null && $response !== null) {
                    break;
                }
            }
        }

        if ($response !== null && $finalLog === null && $elapsed > 15) {
            // The request came back and no log of this run ever appeared:
            // either the endpoint refused it, or this project is not a dind
            // project and never writes one.
            break;
        }

        if ($elapsed > $timeoutSec) {
            $timedOut = true;
            api('POST', "/projects/{$username}/deploy-cancel", null, 60);
            if ($response === null) {
                $response = poll_async($call);
            }
            break;
        }

        usleep(1000000);
    }

    $clientSeconds = round(microtime(true) - $call['started'], 2);
    if ($response === null) {
        $response = poll_async($call);
    }
    finish_async($call);

    // One last read, after the deploy stopped: this is the response that
    // carries the build breakdown, which the engine only computes for a
    // finished deploy (or on ?build_timings=1, which costs a whole-log scan).
    $final = api('GET', "/projects/{$username}/deploy-log?offset=" . NO_LINES_OFFSET, null, 120);
    $finalEntry = is_array($final['body']) ? data($final['body']) : null;
    if (is_array($finalEntry) && ($finalEntry['status'] ?? 'none') !== 'none') {
        $finalLog = $finalEntry;
    }

    return [
        'http_status' => $response['status'] ?? 0,
        'http_error' => $response['error'] ?? null,
        'http_message' => ($response['status'] ?? 0) >= 400 ? message($response['body'] ?? null) : null,
        'client_seconds' => $clientSeconds,
        'first_log_seconds' => $firstLogAt,
        'stage_observed' => $observed,
        'timed_out' => $timedOut,
        'deploy_id' => $finalLog['id'] ?? null,
        'deploy_status' => $finalLog['status'] ?? null,
        'deploy_error' => $finalLog['error'] ?? null,
        'log_lines' => $finalLog['next_offset'] ?? null,
        'server' => $finalLog['timings'] ?? null,
    ];
}

/** The deploy id currently recorded for a project, so a new run can be told from it. */
function current_log_id(string $username): ?string
{
    $log = api('GET', "/projects/{$username}/deploy-log?offset=" . NO_LINES_OFFSET, null, 30);
    $entry = is_array($log['body']) ? data($log['body']) : null;
    $id = is_array($entry) ? ($entry['id'] ?? null) : null;

    return is_string($id) ? $id : null;
}

/**
 * Drop the host image a deploy of this runtime would otherwise reuse, so the
 * next deploy pays to build it. Mode 1, and the one thing here the REST API
 * cannot do: image state belongs to the host's Docker daemon, and the engine
 * exposes no endpoint that touches it.
 *
 * The pattern is derived from the toolchain the preflight resolved, so an
 * account on php 8.1 does not disturb the 8.3 base another test needs. PHP and
 * Ruby have a PanelAlpha-built shared base; Node, Python, Go and Rust use the
 * stock upstream image, and for those "not prewarmed" means the host has no
 * copy to seed into the account.
 *
 * Returns what it ran and what it removed, for the report — a measurement that
 * changed the host has to say so.
 *
 * @return array<string, mixed>
 */
function unprewarm(string $sshTarget, string $runtimeId, string $version, bool $deep = false): array
{
    $pattern = match ($runtimeId) {
        'php' => "^panelalpha/php:{$version}-",
        'ruby' => "^panelalpha/ruby:{$version}-",
        'node' => "^node:{$version}-",
        'python' => "^python:{$version}-",
        default => null,
    };
    if ($pattern === null) {
        return ['skipped' => "no known base image for runtime '{$runtimeId}'"];
    }

    // Listed first, then removed by exact reference: a `docker rmi` driven by
    // a grep over a format string is only as safe as the anchor on the
    // pattern, and this one runs on somebody's production host.
    $list = sprintf(
        'docker images --format %s | grep -E %s',
        escapeshellarg('{{.Repository}}:{{.Tag}}'),
        escapeshellarg($pattern)
    );
    $command = sprintf('ssh -o BatchMode=yes %s %s', escapeshellarg($sshTarget), escapeshellarg($list));
    $found = trim((string) shell_exec($command . ' 2>&1'));
    $images = array_values(array_filter(explode("\n", $found), static fn (string $l): bool => str_contains($l, ':')));

    $removed = [];
    foreach ($images as $image) {
        $rmi = sprintf('ssh -o BatchMode=yes %s %s', escapeshellarg($sshTarget), escapeshellarg('docker rmi -f ' . escapeshellarg($image)));
        $removed[$image] = trim((string) shell_exec($rmi . ' 2>&1'));
    }

    // Removing the tag is not enough to make the next build a real one:
    // BuildKit keeps the layers, so the second app benchmarked on a given PHP
    // minor rebuilds its base from cache in seconds. Pruning the host's build
    // cache is what makes mode 1 mean "never built".
    //
    // Safe to prune here because tenant application builds run inside each
    // account's own DinD daemon, not this one — the host's BuildKit cache
    // holds the shared base images and nothing a customer owns.
    $pruned = null;
    if ($deep) {
        $prune = sprintf('ssh -o BatchMode=yes %s %s', escapeshellarg($sshTarget), escapeshellarg('docker builder prune -af'));
        $pruned = trim((string) shell_exec($prune . ' 2>&1'));
        $pruned = substr((string) preg_replace('/\s+/', ' ', $pruned), -160);
    }

    return [
        'pattern' => $pattern,
        'images' => $images,
        'removed' => $removed,
        'builder_cache_pruned' => $pruned,
        'note' => $images === []
            ? 'the host had no such image — this deploy was already a no-prewarm one'
            : ($deep
                ? 'removed, and the host build cache pruned: the next build is a real one'
                : 'tag removed, layer cache kept — a rebuild may still hit it; --unprewarm-deep also prunes'),
    ];
}

/**
 * What the public URL does, from wherever this script is running.
 *
 * `GET /projects/{u}/app/health` probes 127.0.0.1 inside the container, which
 * is the right check for "did the application boot" and says nothing about the
 * path a visitor takes: DNS, the host's proxy, TLS, and the vhost the deploy
 * wrote. This is that second question, and only that — it is not part of any
 * deploy timing.
 *
 * @return array<string, mixed>
 */
function public_check(string $domain): array
{
    $started = microtime(true);
    $ch = curl_init('https://' . $domain . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_NOBODY => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        // A freshly created domain has no certificate of its own yet, and
        // "the site is up but the cert is not" is a different finding from
        // "the site is down". Record the verdict, do not fail on it.
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
    $error = curl_errno($ch) !== 0 ? curl_error($ch) : null;
    curl_close($ch);

    return [
        'url' => 'https://' . $domain . '/',
        'status' => $status,
        'ttfb_seconds' => round($ttfb, 3),
        'total_seconds' => round(microtime(true) - $started, 3),
        'bytes' => is_string($body) ? strlen($body) : 0,
        'error' => $error,
    ];
}

// ------------------------------------------------------------------ printing

/**
 * One line for what answered: `8000 http HTTP 200 in 0.01s`, per port.
 *
 * `healthy: null` means the application publishes no port to probe, which is
 * not the same as a port that stayed silent — the report keeps them apart and
 * so does this.
 */
function health_summary(mixed $report): string
{
    if (!is_array($report)) {
        return '';
    }
    $parts = [];
    foreach ($report['ports'] ?? [] as $port) {
        $parts[] = sprintf(
            '%s/%s %s%s',
            (string) ($port['port'] ?? '?'),
            (string) ($port['scheme'] ?? '-'),
            (string) ($port['detail'] ?? ''),
            isset($port['time']) && $port['time'] !== null ? sprintf(' in %ss', $port['time']) : ''
        );
    }
    if (isset($report['error'])) {
        $parts[] = (string) $report['error'];
    }
    if ($parts === [] && ($report['healthy'] ?? false) === null) {
        $parts[] = 'the application publishes no port to probe';
    }

    return substr(implode(', ', $parts), 0, 160);
}

/**
 * The phases a deploy is made of, in order, with what each one actually does
 * and how its steps are recognised.
 *
 * The engine's own `phases` block answers "how long"; it does not answer "of
 * what". A 315-second `Starting application` milestone is the build, the
 * image export and the app booting in one line, and those are three different
 * people's problems. So every milestone the pipeline logs is assigned to a
 * phase here, and each phase prints its own steps underneath it.
 *
 * `patterns` are matched in order against the milestone text; the first phase
 * that claims a line owns it. Anything nothing claims lands in `other`, which
 * is printed rather than dropped — a phase model that silently loses time is
 * worse than no phase model.
 *
 * @var array<string, array{what: string, patterns: list<string>}>
 */
const PHASE_MODEL = [
    'account' => [
        'what' => 'OS account, project directory, quota, DinD container, inner dockerd',
        'patterns' => [
            '/^Deploy started/',
            '/^Starting stage: preparing/',
            '/^Stage .preparing. finished/',
            '/home and project director/i',
            '/container|dockerd|daemon/i',
        ],
    ],
    'clone' => [
        'what' => 'fetching the source: git clone, or importing an archive',
        'patterns' => [
            '/^Starting stage: cloning/',
            '/^Cloning repository/',
            '/^Repository cloned/',
            '/^Stage .cloning. finished/',
            '/archive/i',
        ],
    ],
    'detect' => [
        'what' => 'DetectProjectStrategy: which recipe this repository gets',
        'patterns' => [
            '/^Starting stage: running/',
            '/^Detected project type/',
            '/^Using strategy/',
            '/^Using platform/',
            '/overlay/i',
        ],
    ],
    'deploy-files' => [
        // Named for the last thing it logs, which is not the same as what it
        // spends its time on. Between `Using default environment variables` and
        // the next line the pipeline writes, a Node deploy spends 13s with the
        // host image present and 35s without it — the difference being a
        // `docker pull` of node:20-bookworm-slim that is never logged, by a
        // line that then reports the image as coming "from host cache". Any
        // phase model built on the log inherits that blind spot, so this one
        // says so out loud rather than presenting the whole window as file
        // writing.
        'what' => 'writing the Dockerfile, compose file and environment — plus whatever else runs before the next logged line',
        'patterns' => [
            '/environment variables/i',
            '/^Wrote /',
            '/dockerfile|docker-compose/i',
            '/database|sidecar/i',
        ],
    ],
    'base-image' => [
        'what' => 'the shared base image: built on the host if missing, then loaded into the account',
        'patterns' => [
            '/base image/i',
            '/^Preparing shared/',
            '/^Building shared/',
            '/^Loaded base image/',
            '/preload|seeding|seeded/i',
        ],
    ],
    'dependencies-host' => [
        'what' => 'dependency install and asset build run on the host, outside any image layer',
        'patterns' => [
            '/dependencies on host/i',
            '/assets on host/i',
            // The whole install-and-build for a project served from a mounted
            // directory, which logs as one line and is most of its deploy.
            '/^Compiling application on host/',
            '/^Application compiled/',
            '/host build cache/i',
            '/composer|npm|yarn|pnpm|pip/i',
        ],
    ],
    'boot' => [
        'what' => 'compose up — the image build happens inside this window, then the app boots and answers',
        'patterns' => [
            '/^Starting application/',
            '/^Health check/',
            '/^Application/',
            '/compose up/i',
        ],
    ],
    'finish' => [
        'what' => 'closing the deploy record',
        'patterns' => ['/^Deploy finished/', '/^Stage .running. finished/'],
    ],
];

/**
 * Which phase a milestone belongs to.
 */
function phase_of(string $step): string
{
    foreach (PHASE_MODEL as $name => $phase) {
        foreach ($phase['patterns'] as $pattern) {
            if (preg_match($pattern, $step) === 1) {
                return $name;
            }
        }
    }

    return 'other';
}

/**
 * What a build layer is for, so the layer table reads as a bill rather than a
 * list: installing dependencies, compiling the app, or writing the image.
 */
function layer_role(string $command): string
{
    return match (true) {
        preg_match('/\b(npm ci|npm install|yarn install|pnpm install|composer install|pip install|bundle install|go mod|cargo fetch)\b/i', $command) === 1 => 'dependencies',
        preg_match('/\b(run build|npm run|yarn build|pnpm build|tsc|vite|next build|collectstatic|mvn|gradle|cargo build)\b/i', $command) === 1 => 'app build',
        preg_match('/^exporting|^importing|^sending|^load |^transferring/i', $command) === 1 => 'image export',
        preg_match('/^FROM |^COPY |^WORKDIR |^ENV |^EXPOSE |^ENTRYPOINT |^CMD |chmod|entrypoint/i', $command) === 1 => 'image assembly',
        preg_match('/apt-get|apk add|docker-php-ext/i', $command) === 1 => 'system packages',
        default => 'other',
    };
}

/**
 * Did this deploy pay to *build* the shared base image, or only to load one
 * the host already had?
 *
 * This is the difference between measurement mode 1 and mode 2, and it is not
 * a flag anyone passes — it is a fact about the host at the moment of the run,
 * so it is read back out of the log rather than assumed.
 *
 * There are three states, not two, and the third is a trap: `docker rmi`
 * removes the image *tag* and leaves BuildKit's layer cache untouched, so the
 * second app to be benchmarked on a given PHP minor rebuilds its base image
 * from cached layers in seconds and looks like a cheap no-prewarm deploy. A
 * base built with 0 layers cached takes minutes; the same base "built" again on
 * the same minor takes seconds with most layers cached. Same log line, two
 * completely different measurements — so the layer cache decides the wording,
 * not the log line alone.
 *
 * @param list<array<string, mixed>> $timeline
 * @param array<string, mixed> $build
 */
function prewarm_verdict(array $timeline, array $build = []): string
{
    $sawBuild = false;
    $sawLoad = false;
    foreach ($timeline as $entry) {
        $step = (string) ($entry['step'] ?? '');
        if (preg_match('/has not been built on this host yet|^Building shared/', $step) === 1) {
            $sawBuild = true;
        }
        if (preg_match('/^Preparing shared|^Loaded base image/', $step) === 1) {
            $sawLoad = true;
        }
    }

    $steps = (int) ($build['step_count'] ?? 0);
    $cached = (int) ($build['cached_steps'] ?? 0);
    if ($sawBuild && $steps > 0 && $cached / $steps >= 0.5) {
        return sprintf(
            'base image REBUILT from the host BuildKit cache (%d of %d layers cached) — the tag was gone, '
            . 'the layers were not, so this is NOT a true no-prewarm number; use --unprewarm-deep',
            $cached,
            $steps
        );
    }

    return match (true) {
        $sawBuild => 'base image BUILT from source during this deploy (host had neither the image nor its layers)',
        $sawLoad => 'base image loaded from the host (host was prewarmed)',
        // Not the same as "this recipe has no base image": a rebuild does not
        // reload one it already has, and saying "stock image recipe" here
        // would be a claim about the recipe made from the absence of a line.
        default => 'no base-image step in this run (already in the account, or a recipe that uses a stock image)',
    };
}

/**
 * One measured deploy, reported in full: the headline, what the mode had and
 * lacked going in, then every phase with every step inside it.
 *
 * @param array<string, mixed> $run
 * @return array<string, mixed>
 */
function report_run(string $mode, array $run): array
{
    printf(
        "      result   HTTP %d, %s | client waited %.1fs | deploy %ss | first log %ss | %s log lines\n",
        $run['http_status'],
        (string) ($run['deploy_status'] ?? '?'),
        $run['client_seconds'],
        (string) (($run['server']['total_seconds'] ?? null) ?? '-'),
        (string) (($run['first_log_seconds'] ?? null) ?? '-'),
        (string) (($run['log_lines'] ?? null) ?? '-')
    );
    printf("      starts with: %s\n", MODES[$mode]['has']);
    printf("      must do:     %s\n", MODES[$mode]['lacks']);
    if (($run['deploy_error'] ?? null) !== null) {
        printf("      error    %s\n", substr((string) $run['deploy_error'], 0, 220));
    }

    return print_timings($run['server'] ?? null);
}

/**
 * The whole run, phase by phase, with every step inside every phase.
 *
 * @param array<string, mixed>|null $timings
 * @return array<string, mixed> the same breakdown as data, for the JSON document
 */
function print_timings(?array $timings): array
{
    if (!is_array($timings)) {
        echo "      (no timings — the engine recorded no deploy log for this run)\n";
        return [];
    }

    $timeline = $timings['timeline'] ?? [];
    $build = $timings['build'] ?? [];
    $buildSeconds = (float) ($build['total_seconds'] ?? 0);

    // Group every milestone into its phase, keeping order and duration.
    $buckets = [];
    $charged = 0.0;
    foreach ($timeline as $entry) {
        $step = (string) ($entry['step'] ?? '');
        $seconds = $entry['seconds'];
        $phase = phase_of($step);
        $buckets[$phase]['seconds'] = ($buckets[$phase]['seconds'] ?? 0.0) + (float) ($seconds ?? 0);
        $buckets[$phase]['steps'][] = ['at' => $entry['at'] ?? null, 'seconds' => $seconds, 'step' => $step];
        $charged += (float) ($seconds ?? 0);
    }

    // BuildKit layers are always *inside* some other phase, so listing them as
    // a phase of their own means subtracting them from wherever they ran. That
    // is not always `boot`: an app image is built inside the compose-up
    // window, but the shared base image is built inside `base-image`, and on a
    // deploy that has to build the base those layers are the extension
    // compile, not the app. Charging the wrong phase makes the model sum past
    // the deploy total — which is what the `accounted` line below is for.
    $within = null;
    if ($buildSeconds > 0) {
        foreach (['boot', 'base-image'] as $candidate) {
            if (isset($buckets[$candidate]) && $buckets[$candidate]['seconds'] >= $buildSeconds * 0.9) {
                $within = $candidate;
                break;
            }
        }
        if ($within !== null) {
            $buckets[$within]['seconds'] = max(0.0, $buckets[$within]['seconds'] - $buildSeconds);
        }
        // With no phase big enough to have contained them, the layers are
        // still worth listing — but claiming their seconds on top of a total
        // that already includes them is not. Shown at zero, itemised below.
        $buckets['build'] = ['seconds' => $within === null ? 0.0 : $buildSeconds, 'steps' => [], 'within' => $within];
    }

    // Recomputed rather than adjusted: moving the build out of `boot` must not
    // change the total, and the only way to be sure of that is to add the
    // buckets back up after the move.
    $charged = 0.0;
    foreach ($buckets as $bucket) {
        $charged += $bucket['seconds'];
    }

    $order = array_keys(PHASE_MODEL);
    array_splice($order, array_search('boot', $order, true), 0, ['build']);
    $order[] = 'other';

    $total = $timings['total_seconds'];
    printf("      %-18s %8s   %s\n", 'PHASE', 'SECONDS', 'WHAT IT DOES');

    $report = [];
    foreach ($order as $name) {
        if (!isset($buckets[$name])) {
            // Absent, not zero: a compose deploy pulls no base image, and
            // printing 0s there would read as "instant" rather than "did not
            // happen".
            continue;
        }
        $seconds = round($buckets[$name]['seconds'], 1);
        $what = $name === 'build'
            ? sprintf(
                'BuildKit layers, %s (cached layers cost zero)',
                ($buckets['build']['within'] ?? null) !== null
                    ? 'subtracted from ' . $buckets['build']['within'] . ' above, where they ran'
                    : sprintf('%ss of them, already counted inside another phase', round($buildSeconds, 1))
            )
            : (PHASE_MODEL[$name]['what'] ?? 'steps no phase claimed — listed so no time is lost');
        printf(
            "      %-18s %7ss   %s%s\n",
            $name,
            $seconds,
            $what,
            $total ? sprintf('  [%d%%]', $total > 0 ? round($seconds / $total * 100) : 0) : ''
        );
        $report[$name] = ['seconds' => $seconds, 'what' => $what, 'steps' => []];

        foreach ($buckets[$name]['steps'] as $step) {
            // The compose-up milestone is charged the whole window including
            // the build, but the phase above it has had the build subtracted.
            // Printing the raw number under a smaller total reads as an error
            // unless it says why it is bigger.
            $note = ($name === 'boot' && $buildSeconds > 0 && (float) ($step['seconds'] ?? 0) > $seconds)
                ? sprintf('   ← includes the %ss build listed above', round($buildSeconds, 1))
                : '';
            printf(
                "          %7ss  %s%s\n",
                $step['seconds'] ?? '-',
                substr((string) $step['step'], 0, 90),
                $note
            );
            $report[$name]['steps'][] = $step;

            // A single milestone charged many seconds is a window the pipeline
            // went quiet in, not a step that took that long. Saying so is the
            // difference between "writing files took 35s" and "35s passed and
            // nothing was logged" — only one of which is a finding.
            if ($name !== 'build' && (float) ($step['seconds'] ?? 0) >= 5.0 && count($buckets[$name]['steps']) === 1) {
                printf(
                    "                   ↑ the pipeline logged nothing for these %ss; the work in this window is invisible\n",
                    $step['seconds']
                );
            }
        }

        if ($name === 'build' && (int) ($build['step_count'] ?? 0) > 0) {
            printf(
                "          %d layer(s), %d cached, hit ratio %s\n",
                $build['step_count'],
                $build['cached_steps'],
                $build['cache_hit_ratio'] ?? '-'
            );
            foreach ($build['steps'] ?? [] as $layer) {
                $command = (string) $layer['command'];
                printf(
                    "          %7ss  [%-13s] %s%s\n",
                    $layer['seconds'],
                    layer_role($command),
                    substr($command, 0, 72),
                    $layer['cached'] ? '  CACHED' : ''
                );
                $report[$name]['steps'][] = $layer + ['role' => layer_role($command)];
            }
        }
    }

    $stages = [];
    foreach ($timings['stages'] ?? [] as $stage) {
        $stages[] = sprintf('%s %ss', $stage['name'], $stage['seconds'] ?? '-');
    }
    if ($stages !== []) {
        echo "      engine stages (as recorded in the deploy log): " . implode('  ', $stages) . "\n";
    }

    $compose = $timings['compose'] ?? [];
    if ($compose !== []) {
        $parts = [];
        foreach (array_slice($compose, 0, 5) as $step) {
            $parts[] = sprintf('%s %s %ss', $step['object'], $step['action'], $step['seconds']);
        }
        echo "      compose objects: " . implode(', ', $parts) . "\n";
    }

    if ($total !== null) {
        $unaccounted = round((float) $total - $charged, 1);
        printf("      accounted %ss of %ss (%ss unaccounted — the last milestone closes nothing)\n", round($charged, 1), $total, $unaccounted);
    }
    $verdict = prewarm_verdict($timeline, is_array($build) ? $build : []);
    echo "      prewarm: " . $verdict . "\n";

    return ['phases' => $report, 'prewarm' => $verdict];
}

// ---------------------------------------------------------------------- run

$run = [
    'started_at' => date('c'),
    'api' => $baseUrl,
    'options' => $opts + array_map(static fn ($v) => $v, $flags),
    'host' => [],
    'apps' => [],
];

$probe = api('GET', '/test-connection', null, 30);
if ($probe['status'] !== 200) {
    fwrite(STDERR, "Cannot reach {$baseUrl}: HTTP {$probe['status']} " . ($probe['error'] ?? message($probe['body'])) . "\n");
    exit(2);
}

$info = api('GET', '/system/info', null, 60);
$metrics = api('GET', '/metrics/current', null, 60);
$run['host'] = [
    'system_info' => data($info['body'] ?? null),
    'metrics_before' => data($metrics['body'] ?? null),
];

$systemInfo = data($info['body'] ?? null);
$before = data($metrics['body'] ?? null);
printf("Engine %s at %s\n", is_array($systemInfo) ? ($systemInfo['version'] ?? '?') : '?', $baseUrl);
if (is_array($before)) {
    printf(
        "Host before: cpu %.1f%%, ram %.1f%% of %.1f GB, disk %.1f%% of %.1f GB free %.1f GB\n\n",
        (float) ($before['cpu_usage_percent'] ?? 0),
        (float) ($before['ram_usage_percent'] ?? 0),
        ((float) ($before['ram_total'] ?? 0)) / 1048576,
        (float) ($before['disk_usage_percent'] ?? 0),
        ((float) ($before['disk_total'] ?? 0)) / 1073741824,
        ((float) ($before['disk_free'] ?? 0)) / 1073741824
    );
}

$failures = [];

foreach ($fixtures as $fixture) {
    $name = $fixture['name'];
    $username = $fixture['username'];
    $result = ['fixture' => $fixture, 'preflight' => null, 'phases' => [], 'health' => null, 'verdicts' => []];

    printf("== %s (%s) %s%s\n", $name, $username, $fixture['repo'], $fixture['branch'] !== '' ? " @{$fixture['branch']}" : '');

    // -- preflight: what will the engine make of this repository, and at what
    //    toolchain version? Costs a shallow clone on the host and no deploy.
    $payload = ['source' => $fixture['repo'], 'type' => 'git'];
    if ($fixture['branch'] !== '') {
        $payload['branch'] = $fixture['branch'];
    }
    $inspect = api('POST', '/source/inspect', $payload, 300);
    $report = is_array($inspect['body']) ? data($inspect['body']) : null;
    $application = is_array($report) ? ($report['application'] ?? null) : null;

    if (!is_array($application)) {
        printf("   preflight FAILED: HTTP %d %s\n\n", $inspect['status'], $inspect['error'] ?? message($inspect['body']));
        $result['preflight'] = ['ok' => false, 'status' => $inspect['status'], 'message' => message($inspect['body'])];
        $failures[] = "{$name}: source inspect failed";
        $run['apps'][$name] = $result;
        continue;
    }

    $toolchain = [];
    foreach ($application['toolchain'] ?? [] as $tool) {
        $toolchain[(string) $tool['id']] = ['version' => (string) $tool['version'], 'source' => (string) $tool['source']];
    }
    [$wantRuntime, $wantVersion] = explode(' ', $fixture['runtime']);
    $gotVersion = $toolchain[$wantRuntime]['version'] ?? null;

    $result['preflight'] = [
        'ok' => true,
        'seconds' => $inspect['seconds'],
        'strategy' => $application['strategy'] ?? null,
        'label' => $application['label'] ?? null,
        'runtime' => $application['runtime'] ?? null,
        'deployable' => $application['deployable'] ?? null,
        'issue' => $application['issue'] ?? null,
        'toolchain' => $toolchain,
        'commands' => $application['commands'] ?? null,
        'ports' => $report['ports'] ?? null,
        'services' => $report['services'] ?? null,
    ];

    printf(
        "   preflight %.1fs  strategy=%s (want %s)  %s=%s (want %s)  deployable=%s\n",
        $inspect['seconds'],
        (string) ($application['strategy'] ?? '?'),
        $fixture['strategy'],
        $wantRuntime,
        $gotVersion ?? '-',
        $wantVersion,
        ($application['deployable'] ?? false) ? 'yes' : 'no — ' . (string) ($application['issue'] ?? '')
    );

    if (($application['strategy'] ?? null) !== $fixture['strategy']) {
        $result['verdicts'][] = "strategy: got '{$application['strategy']}', want '{$fixture['strategy']}'";
        $failures[] = "{$name}: detected as {$application['strategy']}, expected {$fixture['strategy']}";
    }
    if ($gotVersion !== $wantVersion) {
        $line = "runtime: got {$wantRuntime} " . ($gotVersion ?? 'none') . ", want {$wantRuntime} {$wantVersion}"
            . " (from " . ($toolchain[$wantRuntime]['source'] ?? 'nothing') . ")";
        $result['verdicts'][] = $line;
        echo "   WARN  {$line}\n";
        if ($flags['strict-runtime']) {
            $failures[] = "{$name}: {$line}";
        }
    }
    if (($application['deployable'] ?? false) !== true) {
        $failures[] = "{$name}: not deployable — " . (string) ($application['issue'] ?? '');
        $run['apps'][$name] = $result;
        echo "\n";
        continue;
    }

    if ($flags['preflight-only']) {
        $run['apps'][$name] = $result;
        echo "\n";
        continue;
    }

    // Modes 1 and 2 create the project; modes 3 and 4 need one that already
    // exists. So an existing account is a conflict for the first pair and a
    // prerequisite for the second, and asking for `--modes=rebuild` on an
    // account that is there must not be refused as a name clash.
    $creates = in_array('noprewarm', $modes, true) || in_array('cold', $modes, true);
    $existing = api('GET', "/projects/{$username}", null, 60);

    if (!$creates && $existing['status'] !== 200) {
        printf("   SKIPPED: '%s' does not exist, and no mode here creates it. Add cold to --modes.\n\n", $username);
        $failures[] = "{$name}: project {$username} does not exist";
        $run['apps'][$name] = $result;
        continue;
    }

    // An existing project is never silently overwritten: on a real host that
    // name may belong to something that matters.
    if ($creates && $existing['status'] === 200) {
        if (!$flags['recreate']) {
            printf("   SKIPPED: project '%s' already exists. Pass --recreate to delete and redeploy it.\n\n", $username);
            $failures[] = "{$name}: project {$username} already exists";
            $run['apps'][$name] = $result;
            continue;
        }
        $delete = api('DELETE', "/projects/{$username}", null, 600);
        printf("   deleted existing project (HTTP %d, %.1fs)\n", $delete['status'], $delete['seconds']);
    }

    // The create payload is identical for modes 1 and 2 — the difference is
    // entirely in what the host has before the request, which is the point.
    // No `template`: the endpoint rejects a request carrying both, and a
    // project with a `git_repo` is set to the dind template by the engine
    // itself. Saying it twice is a 422, not a clarification.
    $body = [
        'username' => $username,
        'email' => $username . '@speed-test.invalid',
        'git_repo' => $fixture['repo'],
    ];
    if ($opts['domain-suffix'] !== '') {
        // Left out by default: the engine generates `<username>.local`, which
        // is enough for a health probe that runs inside the container. Pass a
        // real suffix to get a browsable site.
        $body['domain'] = $username . '.' . ltrim((string) $opts['domain-suffix'], '.');
    }
    if ($fixture['branch'] !== '') {
        $body['git_branch'] = $fixture['branch'];
    }
    if ($fixture['env'] !== []) {
        $body['env_vars'] = $fixture['env'];
    }
    if (($fixture['stages'] ?? []) !== []) {
        // Commands this deploy runs, per stage. Applies to this request only —
        // nothing is stored, so a later deploy without it is back on the
        // platform's defaults. Named in the report so an overridden schedule
        // is never mistaken for the recipe's own.
        $body['stages'] = $fixture['stages'];
        printf("   stages   overridden for this deploy: %s\n", implode(', ', array_keys($fixture['stages'])));
    }

    $failed = false;

    // -- mode 1: no prewarm. The host loses its base image for this runtime,
    //    so the deploy has to build it; the account is then thrown away so
    //    mode 2 starts from the same place, minus that one difference.
    if (in_array('noprewarm', $modes, true) && !$failed) {
        echo "   " . MODES['noprewarm']['title'] . " — " . MODES['noprewarm']['what'] . "\n";
        $dropped = unprewarm((string) $opts['unprewarm'], $wantRuntime, (string) ($gotVersion ?? $wantVersion), $flags['unprewarm-deep']);
        $result['unprewarm'] = $dropped;
        printf(
            "      host image: %s\n",
            isset($dropped['skipped'])
                ? $dropped['skipped']
                : sprintf('%s → %s', $dropped['images'] === [] ? 'none matched ' . $dropped['pattern'] : implode(', ', $dropped['images']), $dropped['note'])
        );

        $call = start_async('POST', '/projects', $body, $timeout + 120);
        $cold1 = watch_deploy($username, $call, null, $timeout);
        $cold1['report'] = report_run('noprewarm', $cold1);
        $result['phases']['noprewarm'] = $cold1;

        if (($cold1['deploy_status'] ?? null) !== 'success' || $cold1['http_status'] >= 400) {
            $failures[] = "{$name}: no-prewarm deploy " . (string) ($cold1['deploy_status'] ?? 'failed')
                . ' — ' . substr((string) ($cold1['deploy_error'] ?? $cold1['http_message'] ?? ''), 0, 120);
            $failed = true;
        }

        // Mode 2 needs a fresh account. Deleting here is not the "measure warm
        // by redeploying" trap — that trap is using a delete to measure a
        // rebuild. This is deliberately re-measuring a first deploy.
        if (!$failed || !$flags['keep']) {
            $wipe = api('DELETE', "/projects/{$username}", null, 900);
            printf("      account removed for the next mode (HTTP %d, %.1fs)\n", $wipe['status'], $wipe['seconds']);
        }
    }

    // -- mode 2: cold with prewarm. Account creation, clone, detect, base
    //    image load, dependency install, build, boot.
    if (in_array('cold', $modes, true) && !$failed) {
        echo "   " . MODES['cold']['title'] . " — " . MODES['cold']['what'] . "\n";
        $call = start_async('POST', '/projects', $body, $timeout + 120);
        $cold = watch_deploy($username, $call, null, $timeout);
        $cold['report'] = report_run('cold', $cold);
        $result['phases']['cold'] = $cold;

        if (($cold['deploy_status'] ?? null) !== 'success' || $cold['http_status'] >= 400) {
            $failures[] = "{$name}: cold deploy " . (string) ($cold['deploy_status'] ?? 'failed')
                . ' — ' . substr((string) ($cold['deploy_error'] ?? $cold['http_message'] ?? ''), 0, 120);
            $failed = true;
        }
    }

    if ($failed) {
        // Everything below needs a deployed application. Keep the account for
        // inspection unless the caller asked for a clean host.
        if (!$flags['keep']) {
            api('DELETE', "/projects/{$username}", null, 900);
        }
        $run['apps'][$name] = $result;
        echo "\n";
        continue;
    }

    // -- what the engine believes it deployed, next to what the files say.
    $projectInspect = api('GET', "/projects/{$username}/inspect", null, 300);
    $projectReport = is_array($projectInspect['body']) ? data($projectInspect['body']) : null;
    $result['deployment'] = is_array($projectReport) ? ($projectReport['deployment'] ?? null) : null;
    $result['drift'] = is_array($projectReport) ? ($projectReport['drift'] ?? null) : null;
    if (!empty($result['drift'])) {
        printf("      drift    %s\n", json_encode($result['drift'], JSON_UNESCAPED_SLASHES));
    }

    // -- does it answer? The engine probes 127.0.0.1 inside the container, so
    //    this needs no DNS and no certificate.
    $health = api('GET', "/projects/{$username}/app/health", null, 120);
    $healthReport = is_array($health['body']) ? data($health['body']) : null;
    $result['health'] = $healthReport;
    $healthy = is_array($healthReport) ? ($healthReport['healthy'] ?? null) : null;
    printf(
        "   health   %s  %s\n",
        $healthy === true ? 'ok' : ($healthy === null ? 'unknown' : 'FAILING'),
        health_summary($healthReport)
    );
    if ($healthy !== true) {
        $failures[] = "{$name}: application did not answer its health probe";
    }

    // The visitor's view, when the project has a domain that resolves. Not a
    // deploy timing: it is reported next to one so a site that deployed
    // perfectly and is unreachable from outside cannot be mistaken for a pass.
    if ($opts['domain-suffix'] !== '') {
        $project = api('GET', "/projects/{$username}", null, 60);
        $projectData = data($project['body'] ?? null);
        $domain = is_array($projectData) ? (string) ($projectData['domain'] ?? '') : '';
        if ($domain !== '') {
            $public = public_check($domain);
            $result['public'] = $public;
            printf(
                "   public   %s → %s, first byte %.3fs%s\n",
                $public['url'],
                $public['status'] === 0 ? 'no answer' : 'HTTP ' . $public['status'],
                $public['ttfb_seconds'],
                $public['error'] !== null ? '  (' . substr($public['error'], 0, 80) . ')' : ''
            );
        }
    }

    // -- mode 3: rebuild in place. The production cache path. Deleting and
    //    redeploying instead would destroy the account's BuildKit cache and
    //    measure a first deploy twice.
    if (in_array('rebuild', $modes, true)) {
        echo "   " . MODES['rebuild']['title'] . " — " . MODES['rebuild']['what'] . "\n";
        $previous = current_log_id($username);
        // The same stage overrides the create used. `stages` applies to the
        // request that carries it and is not stored, so a rebuild without it
        // runs the platform's default commands — which is a different build,
        // and measured as a cache miss that says nothing about the cache.
        // Observed exactly that: fastapi's cold install carried a version pin
        // and its rebuild did not, so `pip install` re-ran and the ratio read
        // 0.222 for a reason that had nothing to do with caching.
        $rebuildBody = ($fixture['stages'] ?? []) !== [] ? ['stages' => $fixture['stages']] : [];
        $call = start_async('POST', "/projects/{$username}/rebuild", $rebuildBody, $timeout + 120);
        $warm = watch_deploy($username, $call, $previous, $timeout);
        $warm['report'] = report_run('rebuild', $warm);
        $result['phases']['rebuild'] = $warm;

        if (($warm['deploy_status'] ?? null) !== 'success') {
            $failures[] = "{$name}: rebuild " . (string) ($warm['deploy_status'] ?? 'failed');
        } else {
            $steps = (int) ($warm['server']['build']['step_count'] ?? 0);
            $ratio = $warm['server']['build']['cache_hit_ratio'] ?? null;
            if ($steps > 0 && $ratio !== null && (float) $ratio < $minWarmRatio) {
                $failures[] = sprintf('%s: rebuild cache hit ratio %.3f < %.2f', $name, (float) $ratio, $minWarmRatio);
            }
        }
    }

    // -- mode 4: restart. Container boot with no build at all. Compare it
    //    against itself over time, not against a bare `docker compose up`.
    if (in_array('restart', $modes, true)) {
        echo "   " . MODES['restart']['title'] . " — " . MODES['restart']['what'] . "\n";
        $restart = api('POST', "/projects/{$username}/containers/action", ['action' => 'restart'], $timeout);
        $after = api('GET', "/projects/{$username}/app/health", null, 120);
        $afterReport = is_array($after['body']) ? data($after['body']) : null;
        $result['phases']['restart'] = [
            'http_status' => $restart['status'],
            'client_seconds' => $restart['seconds'],
            'health_seconds' => $after['seconds'],
            'healthy' => is_array($afterReport) ? ($afterReport['healthy'] ?? null) : null,
        ];
        printf(
            "   restart  HTTP %d  %.1fs, answered again after a further %.1fs (%s)\n",
            $restart['status'],
            $restart['seconds'],
            $after['seconds'],
            (is_array($afterReport) && ($afterReport['healthy'] ?? null) === true) ? 'ok' : 'not answering'
        );
    }

    $usage = api('GET', "/projects/{$username}/usage", null, 120);
    $result['usage'] = data($usage['body'] ?? null);

    if (!$flags['keep']) {
        $delete = api('DELETE', "/projects/{$username}", null, 900);
        $result['teardown'] = ['status' => $delete['status'], 'seconds' => $delete['seconds']];
        printf("   teardown HTTP %d  %.1fs\n", $delete['status'], $delete['seconds']);
    }

    $run['apps'][$name] = $result;
    echo "\n";
}

$metricsAfter = api('GET', '/metrics/current', null, 60);
$run['host']['metrics_after'] = data($metricsAfter['body'] ?? null);
$run['finished_at'] = date('c');
$run['failures'] = $failures;

// ------------------------------------------------------------------ summary

echo str_repeat('=', 118), "\n";
echo "THE FOUR MEASUREMENTS (seconds of deploy time, as the engine recorded them)\n\n";
printf(
    "%-9s %-9s %-12s %11s %11s %10s %9s %7s %7s %7s  %s\n",
    'APP',
    'STRATEGY',
    'RUNTIME',
    '1 NOPREWARM',
    '2 COLD',
    '3 REBUILD',
    '4 RESTART',
    'LAYERS',
    'CACHED',
    'RATIO',
    'HEALTH'
);
foreach ($run['apps'] as $name => $result) {
    $pre = $result['preflight'] ?? [];
    [$wantRuntime] = explode(' ', $result['fixture']['runtime']);
    $version = $pre['toolchain'][$wantRuntime]['version'] ?? '-';
    $noprewarm = $result['phases']['noprewarm']['server']['total_seconds'] ?? null;
    $cold = $result['phases']['cold']['server']['total_seconds'] ?? null;
    $rebuild = $result['phases']['rebuild']['server']['total_seconds'] ?? null;
    $restart = $result['phases']['restart']['client_seconds'] ?? null;
    $build = $result['phases']['rebuild']['server']['build'] ?? $result['phases']['cold']['server']['build'] ?? [];
    $healthy = $result['health']['healthy'] ?? null;

    printf(
        "%-9s %-9s %-12s %10ss %10ss %9ss %8ss %7s %7s %7s  %s\n",
        $name,
        (string) ($pre['strategy'] ?? '?'),
        $wantRuntime . ' ' . $version,
        $noprewarm ?? '-',
        $cold ?? '-',
        $rebuild ?? '-',
        $restart !== null ? sprintf('%.1f', $restart) : '-',
        (string) ($build['step_count'] ?? '-'),
        (string) ($build['cached_steps'] ?? '-'),
        (string) ($build['cache_hit_ratio'] ?? '-'),
        $healthy === true ? 'ok' : ($healthy === null ? '-' : 'FAILING')
    );
}

echo "\nWHAT EACH MODE BUYS (the difference between two modes is what the thing between them is worth)\n";
foreach ($run['apps'] as $name => $result) {
    $n = $result['phases']['noprewarm']['server']['total_seconds'] ?? null;
    $c = $result['phases']['cold']['server']['total_seconds'] ?? null;
    $r = $result['phases']['rebuild']['server']['total_seconds'] ?? null;
    $parts = [];
    if ($n !== null && $c !== null) {
        $parts[] = sprintf('host prewarm saves %ss (%s → %s)', $n - $c, $n, $c);
    }
    if ($c !== null && $r !== null) {
        $parts[] = sprintf('caches save %ss (%s → %s)', $c - $r, $c, $r);
    }
    if ($parts !== []) {
        printf("  %-9s %s\n", $name, implode(';  ', $parts));
    }
}

echo "\nPHASE BREAKDOWN PER APP PER MODE (a phase that did not happen is absent, not zero)\n";
foreach ($run['apps'] as $name => $result) {
    foreach (['noprewarm', 'cold', 'rebuild'] as $mode) {
        $breakdown = $result['phases'][$mode]['report']['phases'] ?? null;
        if (!is_array($breakdown)) {
            continue;
        }
        $parts = [];
        foreach ($breakdown as $phase => $entry) {
            $parts[] = sprintf('%s %ss', $phase, $entry['seconds']);
        }
        printf("  %-9s %-10s %s\n", $name, $mode, implode('  ', $parts));
        printf("  %-9s %-10s prewarm: %s\n", '', '', (string) ($result['phases'][$mode]['report']['prewarm'] ?? '-'));
    }
}

if ($opts['json'] !== '') {
    file_put_contents((string) $opts['json'], json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo "\nFull result document: {$opts['json']}\n";
}

if ($failures !== []) {
    echo "\nFAILED:\n";
    foreach ($failures as $failure) {
        echo "  - {$failure}\n";
    }
    exit(1);
}

echo $flags['preflight-only']
    ? "\nEvery repository detected as expected. Nothing was deployed.\n"
    : "\nAll requested apps deployed, answered and rebuilt within the cache threshold.\n";
exit(0);
