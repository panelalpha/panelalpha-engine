<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Health\CheckRegistry;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\AppConfig\LocalAppConfigSource;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\PlatformRegistry;
use App\Lib\Deploy\Platform\SourceRecipes;
use App\Lib\Findings\Finding;
use App\Lib\Project\PublicRouting\ProjectPublicRouting;
use App\Lib\Project\PublicRouting\PublicPort;
use App\Models\User;
use App\System\Privileged\Scripts\HostReadings;
use App\System\Project\Dind;
use Illuminate\Support\Facades\Log;

/**
 * Does the deployed app answer, which `compose up` returning does not say ({@see AppPortAlignment}'s gap, checked)?
 * `healthy` (inside the account) and `reachable` (via the webserver) fail apart; advisory, as workers answer no HTTP.
 */
class AppHealth
{
    public const STATUS_OK = 'ok';

    public const STATUS_FAIL = 'fail';

    /** Docker's word for a container that keeps dying and coming back. */
    private const STATE_RESTARTING = 'restarting';

    /** The other half of that cycle, while the backoff waits. */
    private const STATE_EXITED = 'exited';

    private const STATE_RUNNING = 'running';

    /** The check {@see restartLoopCheck()} adds when it finds one. */
    public const CHECK_RESTART_LOOPING = 'app-restart-looping';

    /** The check {@see restartLoopCheck()} adds for a container that is stopped, not crashing. */
    public const CHECK_APP_STOPPED = 'app-stopped';

    /** What `docker stop` leaves: SIGKILL or SIGTERM, which is not a crash by itself. */
    private const STOP_EXIT_CODES = [137, 143];

    /** The check {@see withBlankPageCheck()} adds, and the serving word it sets. */
    public const CHECK_BLANK_PAGE = 'app-blank-page';

    public const SERVING_BLANK_PAGE = 'blank_page';

    /** The check {@see unreadableSecretsCheck()} adds. */
    public const CHECK_SECRETS_READABLE = 'secrets-readable';

    /** Short: the deploy is already over and one listing of the stack is all this is. */
    private const RESTART_PROBE_TIMEOUT_SECONDS = 20;

    /** The domain answers with what the application answers. */
    public const REACH_OK = AppReachability::OK;

    /** The webserver answered its own 404: no vhost is loaded for this name. */
    public const REACH_NOT_ROUTED = AppReachability::NOT_ROUTED;

    /** Something answered, but not this application. */
    public const REACH_DIFFERS = AppReachability::DIFFERS;

    /** Nothing accepted the connection at all. */
    public const REACH_UNREACHABLE = AppReachability::UNREACHABLE;

    /** No domain, or no answering port to compare against. */
    public const REACH_SKIPPED = AppReachability::SKIPPED;

    /**
     * From here up the app answered but is failing: the green deploy behind a 502 this check exists for, as an open
     * port is not a working app. 4xx stays healthy: plenty of apps legitimately 404 on `/`.
     */
    private const SERVER_ERROR_FROM = 500;

    private Dind $dind;

    public function __construct(Dind $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Published ports worth probing, datastores already filtered out by {@see DetectAppPort}: a Postgres sidecar
     * answering on 5432 says nothing about the application.
     *
     * @return list<int>
     */
    public function ports(): array
    {
        $ports = DetectAppPort::detectAllPorts($this->dind->userAppComposeFileToRun(), $this->dind->environment()->forPortDetection())['all'];
        $user = $this->dind->userModel();

        return self::withRoutedPortFirst(
            self::withRecipePort(
                self::withoutDeclaredPorts(array_values(array_map('intval', $ports)), ProjectPublicRouting::declared($user)->ports, $user->getAppPort()),
                Networking::recipeComposePort($user)
            ),
            $user->getAppPort()
        );
    }

    /**
     * A port published for `public_ports:` carries whatever the app speaks there, not its site, unless the site is routed to it.
     *
     * @param  list<int>  $ports
     * @param  list<PublicPort>  $declared
     * @return list<int>
     */
    public static function withoutDeclaredPorts(array $ports, array $declared, ?int $routed): array
    {
        $declaredPorts = array_map(static fn (PublicPort $port): int => $port->to, $declared);

        return array_values(array_diff($ports, array_diff($declaredPorts, [$routed])));
    }

    /**
     * The port the site is routed to leads, so the checks read what visitors get: a site moved off the lowest port
     * ({@see AppLauncher}) was still judged by that port's 501.
     *
     * @param  list<int>  $ports
     * @return list<int>
     */
    public static function withRoutedPortFirst(array $ports, ?int $routed): array
    {
        return $routed === null || ! in_array($routed, $ports, true)
            ? $ports
            : [$routed, ...array_values(array_diff($ports, [$routed]))];
    }

    /**
     * The port a compose recipe states is the one the site is routed to, so
     * it is probed too, even when the scan refused it as a datastore's.
     *
     * @param  list<int>  $ports
     * @return list<int>
     */
    public static function withRecipePort(array $ports, ?int $recipePort): array
    {
        return $recipePort === null || in_array($recipePort, $ports, true)
            ? $ports
            : [$recipePort, ...$ports];
    }

    /**
     * Probe every published port and report what answered; `healthy` null is nothing to probe, never unhealthy.
     * `error` only when the check could not run (an unreadable compose file fails it, not a 500), not for a dead port.
     *
     * @return array{healthy: bool|null, ports: list<array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>, serving?: string, domain?: array<string, mixed>, checks?: list<array<string, mixed>>, error?: string}
     */
    public function check(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): array
    {
        try {
            $ports = $this->ports();
        } catch (\Exception $e) {
            return [
                'healthy' => false,
                'ports' => [],
                'error' => 'could not read the application compose file: '.self::trimReason($e->getMessage()),
            ];
        }

        if ($ports === []) {
            return ['healthy' => null, 'ports' => []];
        }

        return $this->probePorts($ports, $timeout, $attempts, $delay, $waitOnServerError, $deploy);
    }

    /**
     * An account container that cannot be reached (a wedged sysbox) gives `error` and no ports: reporting each port
     * as not answering would be a lie, as nothing was asked.
     *
     * @param  list<int>  $ports
     * @return array{healthy: bool|null, ports: list<array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>, serving?: string, domain?: array<string, mixed>, checks?: list<array<string, mixed>>, error?: string}
     */
    private function probePorts(array $ports, int $timeout, int $attempts, int $delay, bool $waitOnServerError, ?DeployLogger $deploy): array
    {
        try {
            $raw = $this->runProbe(
                self::probeScript($ports, $timeout, $attempts, $delay, $this->mainDomain(), $waitOnServerError, $this->httpsPorts()),
                self::timeBudget($ports, $timeout, $attempts, $delay),
                $deploy
            );
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            return self::unprobed($e);
        }

        return $this->verdictFor($raw, $ports, $timeout);
    }

    /**
     * The report of a probe docker never ran. A container it says is down is named, not a probe that failed, and is
     * served as stopped: nothing in it could answer.
     *
     * @return array{healthy: false, serving?: string, ports: list<never>, error: string}
     */
    private static function unprobed(\Exception $e): array
    {
        $reason = self::trimReason($e->getMessage());
        if (! $e instanceof DockerErrorException || ! $e->isContainerUnavailable()) {
            return ['healthy' => false, 'ports' => [], 'error' => 'could not run the probe: '.$reason];
        }

        return [
            'healthy' => false,
            'serving' => CheckRunner::SERVING_STOPPED,
            'ports' => [],
            'error' => "the project's container is not running, so nothing was probed ({$reason})",
        ];
    }

    /**
     * The report from the probe's output. `serving` is the third question, beside "did it come up" and "can anyone
     * reach it": is what answered the application, or us?
     *
     * @param  list<int>  $ports
     * @return array{healthy: bool|null, ports: list<array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>, serving?: string, domain?: array<string, mixed>, checks?: list<array<string, mixed>>, error?: string}
     */
    private function verdictFor(string $raw, array $ports, int $timeout): array
    {
        $results = self::parseProbeOutput($raw, $ports);
        $verdict = $this->runChecks($results, $timeout);

        $checks = $verdict['checks'];
        $looping = $this->restartLoopCheck($results);
        if ($looping !== null) {
            $checks[] = $looping;
            if ($looping['id'] === self::CHECK_APP_STOPPED) {
                $verdict['serving'] = CheckRunner::SERVING_STOPPED;
            }
        } elseif (($silent = (new SilentPortCheck($this->dind))->check($results)) !== null) {
            $checks[] = $silent;
        }

        return [
            'healthy' => self::healthyUnlessPlaceholder(self::summarize($results), $verdict['serving']),
            'serving' => $verdict['serving'],
            'ports' => self::withoutBodies($results),
            'domain' => $this->reachability($results, $timeout),
            'checks' => $checks,
        ];
    }

    /** The engine's own placeholder answering is no application running, whatever status it answers with. */
    private static function healthyUnlessPlaceholder(?bool $healthy, string $serving): ?bool
    {
        return $serving === CheckRunner::SERVING_PLACEHOLDER ? false : $healthy;
    }

    /**
     * Within a deploy, its cancel stops the probe at once rather than after every attempt has run out.
     */
    private function runProbe(string $script, int $budget, ?DeployLogger $deploy): string
    {
        $shell = $this->dind->shell();

        return $deploy === null
            ? $shell->execQuiet(['bash', '-c', $script], [], $budget)
            : $shell->execQuietUntilCancelled(['bash', '-c', $script], $deploy, $budget);
    }

    /**
     * The app port when the recipe declared it HTTPS-only (`port_scheme: https`).
     *
     * @return list<int>
     */
    private function httpsPorts(): array
    {
        $user = $this->dind->userModel();
        $port = $user->getAppPort();

        return $user->getAppPortScheme() === 'https' && $port !== null ? [$port] : [];
    }

    /** The name visitors use; null before the project has one or when unreadable, and the probe asks as 127.0.0.1. */
    private function mainDomain(): ?string
    {
        try {
            $domain = $this->dind->userModel()->getMainDomain()?->domain;
        } catch (\Throwable) {
            return null;
        }

        return is_string($domain) && $domain !== '' ? $domain : null;
    }

    /**
     * When nothing answered, whether Docker has the app restarting or stopped: no response meant no check, which read
     * like a working worker. Quiet: under `report()`'s deploy logger the streaming call would log a page of compose JSON.
     *
     * @param  list<array<string, mixed>>  $results  as {@see parseProbeOutput} produced them
     * @return array<string, mixed>|null
     */
    private function restartLoopCheck(array $results): ?array
    {
        if ($results === []) {
            return null;
        }
        foreach ($results as $result) {
            if (($result['status'] ?? null) === self::STATUS_OK) {
                return null;
            }
        }

        try {
            $raw = $this->dind->accountDaemon()->stackPs(true, self::RESTART_PROBE_TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            Log::debug('Restart-loop probe could not run: '.self::trimReason($e->getMessage()));

            return null;
        }

        $restarts = $this->restartCountsIfSignalled($raw);

        return self::restartLoopCounting($raw, $restarts)
            ?? (self::nothingAt($results) ? self::stoppedFrom($raw, $this->oneShotServices(), $restarts) : null);
    }

    /**
     * Restart counts by container name, read only when a container exited on a stop signal: that alone does not say
     * whether `docker stop` or a crash loop's kill put it there.
     *
     * @return array<string, int>
     */
    private function restartCountsIfSignalled(string $composePsJson): array
    {
        $signalled = false;
        foreach (preg_split('/\R/', trim($composePsJson)) ?: [] as $line) {
            $row = json_decode(trim($line), true);
            $signalled = $signalled || (is_array($row) && ($row['State'] ?? null) === self::STATE_EXITED
                && in_array((int) ($row['ExitCode'] ?? 0), self::STOP_EXIT_CODES, true));
        }
        $snapshot = $signalled ? $this->containerSnapshot() : null;
        if ($snapshot === null) {
            return [];
        }

        $counts = [];
        foreach (self::inspectRows($snapshot) as $row) {
            $counts[$row['name']] = $row['restarts'];
        }

        return $counts;
    }

    /** No port gave any HTTP answer: a stopped container cannot have. @param list<array<string, mixed>> $results */
    private static function nothingAt(array $results): bool
    {
        foreach ($results as $result) {
            if (is_int($result['http_code'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> services that are meant to run once and exit */
    private function oneShotServices(): array
    {
        try {
            $compose = ComposeYaml::parse((string) $this->dind->system()->filesystem()->fileGetContents(
                $this->dind->userAppComposeFileToRun()
            ));
        } catch (\Throwable) {
            return [];
        }

        return is_array($compose) ? AppLauncher::oneShotServices($compose) : [];
    }

    /**
     * Services stopped rather than crashing: `exited` with 0 or a stop signal and no restart in progress, `created` or
     * `paused`. A one-shot that finished is allowed to have finished.
     *
     * @param  list<string>  $oneShots
     * @param  array<string, int>  $restarts  by container name, see {@see isCrashing()}
     * @return array<string, mixed>|null
     */
    public static function stoppedFrom(string $composePsJson, array $oneShots = [], array $restarts = []): ?array
    {
        $stopped = [];
        foreach (preg_split('/\R/', trim($composePsJson)) ?: [] as $line) {
            $row = json_decode(trim($line), true);
            if (! is_array($row) || self::isCrashing($row, $restarts)) {
                continue;
            }
            $service = self::stringOrNull($row['Service'] ?? null);
            if (! in_array(strtolower((string) ($row['State'] ?? '')), [self::STATE_EXITED, 'created', 'paused'], true)
                || ($service !== null && in_array($service, $oneShots, true))
            ) {
                continue;
            }
            $status = self::stringOrNull($row['Status'] ?? null);
            $stopped[] = trim(($service ?? self::stringOrNull($row['Name'] ?? null) ?? 'a service').($status === null ? '' : " ({$status})"));
        }

        if ($stopped === []) {
            return null;
        }

        return self::stoppedCheck($stopped);
    }

    /**
     * @param  list<string>  $stopped
     * @return array<string, mixed>
     */
    private static function stoppedCheck(array $stopped): array
    {
        return [
            'id' => self::CHECK_APP_STOPPED,
            'group' => 'runtime',
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
            'title' => 'The application container is stopped.',
            'detail' => 'Docker reports '.implode(', ', $stopped).'. Nothing answered because it is not running.',
            'fix' => 'Start it again, or redeploy.',
            'evidence' => ['stopped' => $stopped],
        ];
    }

    /**
     * Stored secrets that cannot be decoded: the app may serve fine, but the next save stores them empty. Only the
     * health endpoint asks, so it never makes a deploy partial.
     *
     * @return array<string, mixed>|null
     */
    public static function unreadableSecretsCheck(User $user): ?array
    {
        $names = $user->unreadableSecrets();
        if ($names === []) {
            return null;
        }

        return [
            'id' => self::CHECK_SECRETS_READABLE,
            'group' => '_baseline',
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_WARNING,
            'title' => 'Stored secrets cannot be decoded.',
            'detail' => (string) $user->unreadableSecretsWarning(),
            'fix' => User::UNREADABLE_SECRETS_REMEDY,
            'evidence' => ['secrets' => $names],
        ];
    }

    /**
     * The decision over `docker compose ps --format json --all` output (one JSON object per line), apart from fetching
     * it so it tests without a container: the difference between a worker and a crash loop is already in that output.
     *
     * @return array<string, mixed>|null
     */
    public static function restartLoopFrom(string $composePsJson): ?array
    {
        return self::restartLoopCounting($composePsJson, []);
    }

    /**
     * The same, with restart counts by container name to tell `docker stop`
     * from a crash ({@see isCrashing()}).
     *
     * @param  array<string, int>  $restarts
     * @return array<string, mixed>|null
     */
    public static function restartLoopCounting(string $composePsJson, array $restarts): ?array
    {
        $restarting = [];
        foreach (preg_split('/\R/', trim($composePsJson)) ?: [] as $line) {
            $row = json_decode(trim($line), true);
            if (! is_array($row) || ! self::isCrashing($row, $restarts)) {
                continue;
            }
            $name = self::stringOrNull($row['Service'] ?? null) ?? self::stringOrNull($row['Name'] ?? null);
            $status = self::stringOrNull($row['Status'] ?? null);
            $restarting[] = trim(($name ?? 'a service').($status === null ? '' : " ({$status})"));
        }

        if ($restarting === []) {
            return null;
        }

        return [
            'id' => self::CHECK_RESTART_LOOPING,
            'group' => 'runtime',
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
            'title' => 'The application is restarting, not running.',
            'detail' => 'Docker reports '.implode(', ', $restarting)
                .'. Nothing answered on any published port because the process keeps exiting.',
            'fix' => 'Read the container output; the reason it exits is there.',
            'evidence' => ['restarting' => $restarting],
        ];
    }

    /**
     * A crash loop in either half of its cycle: `restarting`, or `exited` non-zero while the backoff waits (exit 0 is
     * a finished one-shot). A stop signal (137, 143) is `docker stop` if Docker never restarted it; no count, a crash.
     *
     * @param  array<string, mixed>  $row  one `docker compose ps --format json` row
     * @param  array<string, int>  $restarts  restart counts by container name
     */
    private static function isCrashing(array $row, array $restarts = []): bool
    {
        $state = $row['State'] ?? null;
        if ($state === self::STATE_RESTARTING) {
            return true;
        }

        $code = (int) ($row['ExitCode'] ?? 0);
        $name = ltrim((string) ($row['Name'] ?? ''), '/');
        if (in_array($code, self::STOP_EXIT_CODES, true) && ($restarts[$name] ?? null) === 0) {
            return false;
        }

        return $state === self::STATE_EXITED && $code !== 0;
    }

    /**
     * Ask the application the checks its runtime brings. Never throws: a verdict that could not be produced is one
     * missing section of a report, and losing it must not cost the port results gathered.
     *
     * @param  list<array<string, mixed>>  $results  as {@see parseProbeOutput} produced them
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    private function runChecks(array $results, int $timeout = 5): array
    {
        try {
            $details = $this->dind->userModel()->getDetails();
            $runner = self::checkRunnerFor($details);
            $first = self::firstResponse($results);

            return ErrorPageCheck::apply(self::withBlankPageCheck(
                $runner->runByPath(
                    [HealthCheck::DEFAULT_PATH => $first] + $this->fetchPaths($runner->paths(), $results, $timeout),
                    $this->dind->userAppDirPath()
                ),
                self::stringOrNull($details[self::DETAIL_RUNTIME] ?? null),
                $first
            ), $first);
        } catch (\Throwable $e) {
            Log::debug('Health checks could not run: '.self::trimReason($e->getMessage()));

            return ['serving' => CheckRunner::SERVING_UNKNOWN, 'checks' => []];
        }
    }

    /**
     * A 2xx with nothing in it, a blank page to a visitor: 204 and 205 mean no content on purpose, and any body (`{}`
     * included) is content. A full sample may start a longer page; only a shorter one is the whole body.
     */
    public static function isBlankPage(int $status, string $body): bool
    {
        return $status >= 200 && $status < 300 && ! in_array($status, [204, 205], true)
            && strlen($body) < ProbedResponse::SAMPLE_BYTES && trim($body) === '';
    }

    /**
     * A front page answering 2xx with nothing in it is not serving in any runtime: no check can match absent text, and
     * every visitor gets a blank page. In PHP it is usually a fatal the application suppresses itself.
     *
     * @param  array{serving: string, checks: list<array<string, mixed>>}  $verdict
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    public static function withBlankPageCheck(array $verdict, ?string $runtime, ProbedResponse $front): array
    {
        if (! self::isBlankPage($front->status, $front->body)) {
            return $verdict;
        }
        $php = $runtime === PlatformManifest::RUNTIME_PHP;

        $outranked = self::hasFailedError($verdict['checks']);

        $verdict['checks'][] = [
            'id' => self::CHECK_BLANK_PAGE,
            'group' => $php ? PlatformManifest::RUNTIME_PHP : CheckRegistry::BASELINE,
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
            'title' => "The front page answered {$front->status} with an empty page.",
            'detail' => "{$front->url} returned no content.",
            'fix' => $php
                ? 'Usually a PHP fatal error the application suppresses. Read the container output, '
                    .'or turn on the application\'s debug mode, to see it.'
                : 'Read the container output to see why it sends nothing. '
                    .'A route meant to send nothing answers 204.',
            'evidence' => ['url' => $front->url, 'http_code' => $front->status],
        ];
        if (! $outranked) {
            $verdict['serving'] = self::SERVING_BLANK_PAGE;
        }

        return $verdict;
    }

    /**
     * Errors outrank warnings, as in CheckRunner: an error already found says more than "empty".
     *
     * @param  list<array<string, mixed>>  $checks
     */
    public static function hasFailedError(array $checks): bool
    {
        $outranked = false;
        foreach ($checks as $check) {
            $outranked = $outranked || (($check['status'] ?? null) === CheckResult::STATUS_FAIL
                && ($check['severity'] ?? null) === HealthCheck::SEVERITY_ERROR);
        }

        return $outranked;
    }

    /**
     * The checks for this account: its runtime's group, what the platform's manifest added, and the ones its source
     * recipe ships in `checks/` (frozen onto the account as `deploy_checks_dir`).
     *
     * @param  array<string, mixed>  $details
     */
    public static function checkRunnerFor(array $details): CheckRunner
    {
        $manifest = self::declaredManifest($details);

        return CheckRunner::forWithDirectory(
            self::stringOrNull($details[self::DETAIL_RUNTIME] ?? null),
            $manifest?->checks ?? [],
            self::stringOrNull($details[self::DETAIL_CHECKS_DIR] ?? null),
            $manifest?->checkSkips ?? []
        );
    }

    /**
     * Fetch the paths the checks name besides `/`, once each, from the port firstResponse() chose (any answer, a 5xx
     * included). Nothing when no port answered or nothing asks.
     *
     * @param  list<string>  $paths
     * @param  list<array<string, mixed>>  $results
     * @return array<string, ProbedResponse>
     */
    private function fetchPaths(array $paths, array $results, int $timeout): array
    {
        $answered = null;
        foreach ($results as $result) {
            if (is_int($result['http_code'] ?? null) && $result['http_code'] > 0) {
                $answered = $result;
                break;
            }
        }
        if ($paths === [] || $answered === null) {
            return [];
        }
        $scheme = is_string($answered['scheme'] ?? null) ? $answered['scheme'] : 'http';
        $port = (int) $answered['port'];

        $raw = $this->dind->shell()->execQuiet(
            ['bash', '-c', self::pathProbeScript($scheme, $port, $paths, $timeout, $this->mainDomain())],
            [],
            30 + count($paths) * max(1, $timeout)
        );

        return self::parsePathProbeOutput($raw, $scheme, $port);
    }

    /**
     * One curl per path against the port that answered, echoing "PATH<tab>CODE TIME<tab>BODY" (body base64), with the
     * Host {@see probeScript()} sends, so a Host-validating app answers its recipe paths as it answered `/`.
     *
     * @param  list<string>  $paths
     */
    public static function pathProbeScript(
        string $scheme,
        int $port,
        array $paths,
        int $timeout = 5,
        ?string $domain = null
    ): string {
        $timeout = max(1, $timeout);
        $domain = $domain !== null && trim($domain) !== '' ? strtolower(trim($domain)) : null;
        $hostHeader = self::visitorHeaders($domain);
        $sample = ProbedResponse::SAMPLE_BYTES;
        $base = escapeshellarg(($scheme === 'https' ? 'https' : 'http').'://127.0.0.1:'.$port);
        $list = implode(' ', array_map('escapeshellarg', $paths));

        return <<<SH
set -u
for path in {$list}; do
    body_file=\$(mktemp)
    out=\$(curl -sS -k {$hostHeader}-o "\$body_file" -w '%{http_code} %{time_total}' --max-time {$timeout} {$base}"\$path" 2>/dev/null) || true
    printf '%s\\t%s\\t%s\\n' "\$path" "\${out:-000 0}" "\$(head -c {$sample} "\$body_file" | base64 | tr -d '\\r\\n')"
    rm -f "\$body_file"
done
exit 0
SH;
    }

    /**
     * @return array<string, ProbedResponse> by path; a path that got nothing
     *                                       is {@see ProbedResponse::none()}, which the check reports.
     */
    public static function parsePathProbeOutput(string $raw, string $scheme, int $port): array
    {
        $responses = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            $fields = explode("\t", $line);
            if (count($fields) < 2 || ! str_starts_with($fields[0], '/')) {
                continue;
            }
            $path = $fields[0];
            $measured = preg_split('/\s+/', trim($fields[1])) ?: [];
            $code = (int) ($measured[0] ?? 0);
            $url = "{$scheme}://127.0.0.1:{$port}{$path}";
            $responses[$path] = $code > 0
                ? new ProbedResponse($code, self::decodeSample(trim($fields[2] ?? '')), $url, (float) ($measured[1] ?? 0))
                : ProbedResponse::none($url);
        }

        return $responses;
    }

    /**
     * The deployed platform's manifest (checks added and skipped), read live so a manifest edit reaches the next health
     * check without a redeploy; looked up with find(), not all(), as only it resolves a source recipe's id.
     *
     * @param  array<string, mixed>  $details
     */
    private static function declaredManifest(array $details): ?PlatformManifest
    {
        $platform = self::stringOrNull($details[self::DETAIL_PLATFORM] ?? null);
        if ($platform === null) {
            return null;
        }

        $recipe = self::appliedRecipe($details, $platform);
        if ($recipe !== null) {
            return $recipe;
        }

        $manifest = PlatformRegistry::find($platform);
        $home = self::stringOrNull($details['home_dir'] ?? null);
        if ($manifest !== null || $home === null) {
            return $manifest;
        }

        return self::repositoryManifest($platform, $home);
    }

    /**
     * The source recipe the account's repository names, if its id is $platform: one with no id keeps the one it extends
     * (Apaxy's `dockerfile`), and the id check keeps a deploy that pinned another recipe on that one.
     *
     * @param  array<string, mixed>  $details
     */
    private static function appliedRecipe(array $details, string $platform): ?PlatformManifest
    {
        try {
            $recipe = SourceRecipes::for(self::stringOrNull($details['git_repo'] ?? null));
            if ($recipe !== null && $recipe->id === $platform) {
                return $recipe;
            }
        } catch (\Throwable $e) {
            Log::debug('Source recipe unreadable for health checks: '.self::trimReason($e->getMessage()));
        }

        return null;
    }

    /** A repository's own manifest with an id of its own, which is registered nowhere. */
    private static function repositoryManifest(string $platform, string $home): ?PlatformManifest
    {
        try {
            $appConfig = AppConfig::load(
                new LocalAppConfigSource,
                rtrim($home, '/').'/project'
            );

            return PlatformRegistry::forDecisionOrAppConfig(['platform' => $platform], $appConfig);
        } catch (\Throwable $e) {
            Log::debug('Project manifest unreadable for health checks: '.self::trimReason($e->getMessage()));

            return null;
        }
    }

    /**
     * The response the checks are asked about: the first port that answered at all, a 5xx included, as that is what
     * `no-server-error` exists to describe.
     *
     * @param  list<array<string, mixed>>  $results
     */
    private static function firstResponse(array $results): ProbedResponse
    {
        foreach ($results as $result) {
            $code = $result['http_code'] ?? null;
            if (is_int($code) && $code > 0) {
                $scheme = is_string($result['scheme'] ?? null) ? $result['scheme'] : 'http';
                $path = is_string($result['path'] ?? null) ? $result['path'] : HealthCheck::DEFAULT_PATH;

                return new ProbedResponse(
                    $code,
                    is_string($result['body'] ?? null) ? $result['body'] : '',
                    "{$scheme}://127.0.0.1:{$result['port']}{$path}",
                    is_numeric($result['time'] ?? null) ? (float) $result['time'] : 0.0,
                    $path
                );
            }
        }

        return ProbedResponse::none();
    }

    /**
     * The port results as a caller sees them: the body sample is evidence for the checks, and a page of somebody's
     * application has no business in an API response about whether it is up, and could be large.
     *
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private static function withoutBodies(array $results): array
    {
        return array_map(static function (array $result): array {
            unset($result['body'], $result['path']);

            return $result;
        }, $results);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Does this project's domain serve this project's application? See
     * {@see AppReachability}. Never throws.
     *
     * @param  list<array<string, mixed>>  $ports  as {@see check()} produced them
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public function reachability(array $ports, int $timeout = 5): array
    {
        return (new AppReachability($this->dind))->check($ports, $timeout);
    }

    /** Seconds between edge re-probes while a just-reloaded vhost settles. */
    public const EDGE_RETRY_DELAYS = AppReachability::EDGE_RETRY_DELAYS;

    /**
     * @param  callable(): array{code: int, hash: string, default404: bool}  $probeEdge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @param  (callable(int): void)|null  $sleep
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function awaitRoute(string $domain, callable $probeEdge, array $app, ?callable $sleep = null): array
    {
        return AppReachability::awaitRoute($domain, $probeEdge, $app, $sleep);
    }

    /**
     * @param  array{code: int, hash: string, default404: bool}  $edge
     * @param  array{code: int, hash: string, default404: bool}  $app
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function compareFingerprints(string $domain, array $edge, array $app): array
    {
        return AppReachability::compareFingerprints($domain, $edge, $app);
    }

    public static function edgeProbeScript(string $domain, string $ip, int $timeout = 5, bool $https = false): string
    {
        return (new HostReadings)->edgeProbe($domain, $ip, $timeout, $https);
    }

    public static function appProbeScript(string $scheme, int $port, int $timeout = 5, ?string $domain = null): string
    {
        return AppReachability::appProbeScript($scheme, $port, $timeout, $domain);
    }

    /** @return array{code: int, hash: string, default404: bool, hash2: string, location: string, scheme: string} */
    public static function parseFingerprint(string $raw): array
    {
        return AppReachability::parseFingerprint($raw);
    }

    /** @param array{verdict: string, domain: ?string, http_code: ?int, detail: string} $result */
    public static function describeReach(array $result): string
    {
        return AppReachability::describe($result);
    }

    /**
     * Detail keys the verdict is remembered under, so the pipeline and the telemetry report both read what the probe
     * found without running it again.
     */
    public const DETAIL_CHECKED = 'health_checked';

    /** Only whether the app came up: persisted and served over the API, so a routing failure must not redefine it. */
    public const DETAIL_HEALTHY = 'health_healthy';

    public const DETAIL_PORTS = 'health_ports';

    /** The reachability verdict, one of the REACH_* constants. */
    public const DETAIL_REACHABLE = 'health_reachable';

    /** What the webserver answered for the domain, beside that verdict. */
    public const DETAIL_REACHABLE_CODE = 'health_reachable_code';

    /** An edge that answers a gateway error while the app answers: a site visitors cannot use. */
    private const EDGE_GATEWAY_ERRORS = [502, 503, 504];

    /** What the application is serving, one of {@see CheckRunner}'s words. */
    public const DETAIL_SERVING = 'health_serving';

    /** The checks that failed, so the deploy can act on their severities. */
    public const DETAIL_CHECKS = 'health_failed_checks';

    /** Every key {@see remember()} writes. */
    private const VERDICT_DETAILS = [
        self::DETAIL_CHECKED,
        self::DETAIL_HEALTHY,
        self::DETAIL_REACHABLE,
        self::DETAIL_REACHABLE_CODE,
        self::DETAIL_SERVING,
        self::DETAIL_CHECKS,
        self::DETAIL_PORTS,
    ];

    /**
     * What to say when an app publishes ports and answers on none; here, not in a controller, so the rebuild path says
     * the same thing.
     */
    public const NOT_ANSWERING = 'The application started but did not answer on any port it publishes. '
        .'The deploy log has the per-port result; a worker or queue-only app can ignore this.';

    public const CODE_NOT_ANSWERING = 'app_not_answering';

    public const CODE_SITE_GATEWAY_ERROR = 'site_gateway_error';

    public const CODE_SITE_UNREACHABLE = 'site_unreachable';

    public const CODE_CHECK_FAILED = 'health_check_failed';

    /** Frozen by the deploy; the runtime decides which check group applies. */
    public const DETAIL_RUNTIME = 'deploy_runtime';

    /** Frozen by the deploy; the platform decides which checks it added. */
    public const DETAIL_PLATFORM = 'deploy_platform';

    /** Frozen by the deploy: the source recipe's own `checks/`, or null. */
    public const DETAIL_CHECKS_DIR = 'deploy_checks_dir';

    /** Wait after an answer before re-asking Docker; MintHCM exited 1s after its first 200. */
    private const SETTLE_SECONDS = 5;

    /** Settles a restart must survive to count as over: Docker itself calls ten seconds up a good start. */
    private const RESTARTS_OVER_LOOKS = 2;

    /**
     * The deploy-time probe budget, longer than an operator's check: stock laravel/laravel binds only after a minute
     * of setup. A healthy app pays nothing, as the first answer ends the loop.
     */
    private const DEPLOY_TIMEOUT = 3;

    private const DEPLOY_ATTEMPTS = 15;

    private const DEPLOY_DELAY = 4;

    /**
     * Past that budget a container that is up and bound to nothing yet gets this long (Magento runs setup:install at
     * start); a manifest's `health: {start_period}` replaces it, frozen as {@see DETAIL_START_PERIOD}.
     */
    private const START_GRACE_SECONDS = 300;

    private const START_POLL_SECONDS = 15;

    public const DETAIL_START_PERIOD = 'deploy_start_period';

    /**
     * Run the check, log each port and remember the verdict: not gating, as a queue consumer answers nothing on HTTP;
     * throws only on cancel. Snapshot first, so a restart during the probe counts; 500s are waited out (still booting).
     *
     * @return array<string, mixed>|null the report, when there was one with ports to read
     */
    public function report(): ?array
    {
        $logger = $this->dind->shell()->logger();
        if ($logger === null) {
            return null;
        }

        $before = $this->containerSnapshot();
        $report = $this->observe(self::DEPLOY_TIMEOUT, self::DEPLOY_ATTEMPTS, self::DEPLOY_DELAY, true, $logger);
        if ($report === null) {
            return null;
        }

        if (isset($report['error'])) {
            $logger->warn('Health check: '.$report['error']);

            return null;
        }

        if ($report['healthy'] === null) {
            $logger->dim('Health check: the application publishes no port to probe');
            $this->reportChecks($this->withLateRestartLoop($report, $before), $logger);

            return null;
        }

        return $this->reportPorts($report, $before, $logger);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>|null  $before
     * @return array<string, mixed>
     */
    private function reportPorts(array $report, ?array $before, DeployLogger $logger): array
    {
        $report = $this->awaitSlowStart($report, $before, $logger);
        $report = $this->withLateRestartLoop($report, $before);

        $placeholder = ($report['serving'] ?? null) === CheckRunner::SERVING_PLACEHOLDER;
        foreach ($report['ports'] as $result) {
            if ($result['status'] !== self::STATUS_OK) {
                $logger->warn(self::describe($result));
            } elseif ($placeholder) {
                $logger->warn(self::describe($result).' with the PanelAlpha placeholder page: no application is running');
            } else {
                $logger->ok(self::describe($result));
            }
        }

        $this->reportReachability($report, $logger);
        $this->reportChecks($report, $logger);

        return $report;
    }

    /**
     * Why an app started with no deploy log (a push's) is not running ({@see notRunning()}), read as {@see report()}
     * reads a deploy's; null when it runs. A start refused for this is undone, so the verdict from before is put back.
     */
    public function startFailure(): ?string
    {
        $before = $this->containerSnapshot();
        $previous = $this->verdictNow();
        $report = $this->observe(self::DEPLOY_TIMEOUT, self::DEPLOY_ATTEMPTS, self::DEPLOY_DELAY, true);
        if ($report === null || isset($report['error'])) {
            return null;
        }
        if ($report['healthy'] !== null) {
            $report = $this->awaitSlowStart($report, $before, null);
        }
        $failure = self::notRunning($this->withLateRestartLoop($report, $before));
        if ($failure !== null) {
            $this->putBackVerdict($previous);
        }

        return $failure;
    }

    /**
     * What says a report's app is not running: nothing answered on any port it publishes, and it restarts, is stopped
     * or still listens on nothing. One that answered, even an error, or publishes no port, a deploy calls partial.
     *
     * @param  array<string, mixed>  $report
     */
    public static function notRunning(array $report): ?string
    {
        $ports = (array) ($report['ports'] ?? []);
        if ($ports === [] || ! self::nothingAt($ports)) {
            return null;
        }
        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (is_array($check) && self::saysNotRunning($check)) {
                return trim(implode(' ', array_filter([$check['title'] ?? null, $check['detail'] ?? null], is_string(...))));
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $check */
    private static function saysNotRunning(array $check): bool
    {
        return in_array($check['id'] ?? null, [self::CHECK_RESTART_LOOPING, self::CHECK_APP_STOPPED], true)
            || SilentPortCheck::starting($check) !== [];
    }

    /** @return array<string, mixed> the remembered verdict, null for a key never written */
    private function verdictNow(): array
    {
        $details = $this->dind->userModel()->getDetails();

        return array_intersect_key($details, array_flip(self::VERDICT_DETAILS)) + array_fill_keys(self::VERDICT_DETAILS, null);
    }

    /** @param  array<string, mixed>  $verdict  from {@see verdictNow()} */
    private function putBackVerdict(array $verdict): void
    {
        try {
            $user = $this->dind->userModel();
            $user->setDetails($verdict);
            $user->save();
        } catch (\Throwable $e) {
            Log::debug('Could not put back the app health verdict: '.$e->getMessage());
        }
    }

    /**
     * One answer is not an app that stays up (MintHCM answered 200, then looped behind a 502), nor is a portless worker
     * restarting forever. Settles first after an answer or with nothing probed, so a crashing service can show it.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>|null  $before
     * @return array<string, mixed>
     */
    private function withLateRestartLoop(array $report, ?array $before): array
    {
        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (is_array($check) && ($check['id'] ?? null) === self::CHECK_RESTART_LOOPING) {
                return $report;
            }
        }
        if (self::answeredOrUnprobed($report)) {
            $this->settle();
        }

        $looping = $this->lateRestartLoop($before);
        if ($looping === null) {
            return $report;
        }

        $report['checks'][] = $looping;
        $this->remember($report);

        return $report;
    }

    /**
     * Nothing to probe means nothing was waited for either: give a crashing service
     * the same few seconds an answer gets to show it.
     *
     * @param  array<string, mixed>  $report
     */
    private static function answeredOrUnprobed(array $report): bool
    {
        if (($report['healthy'] ?? null) === null) {
            return true;
        }
        foreach ((array) ($report['ports'] ?? []) as $port) {
            if (is_array($port) && ($port['status'] ?? null) === self::STATUS_OK) {
                return true;
            }
        }

        return false;
    }

    /**
     * A restart since $before, unless every restarted container is up and stays up:
     * an app that crashed while its database was still booting and then serves is not looping.
     *
     * @param  list<array<string, mixed>>|null  $before
     * @return array<string, mixed>|null
     */
    private function lateRestartLoop(?array $before): ?array
    {
        $after = $this->containerSnapshot();
        $looping = $after === null ? null : self::restartLoopBetween($before, $after, $this->appServices());
        if ($looping === null || ! self::restartedAreRunning($after)) {
            return $looping;
        }

        return $this->restartsAreOver($after) ? null : $looping;
    }

    /** @param  list<array<string, mixed>>  $after */
    private function restartsAreOver(array $after): bool
    {
        for ($look = 0; $look < self::RESTARTS_OVER_LOOKS; $look++) {
            $this->settle();
            $later = $this->containerSnapshot();
            if ($later === null || self::restartLoopBetween($after, $later) !== null || ! self::restartedAreRunning($later)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every container Docker has restarted is running.
     *
     * @param  list<array<string, mixed>>  $snapshot
     */
    private static function restartedAreRunning(array $snapshot): bool
    {
        foreach (self::inspectRows($snapshot) as $row) {
            if ($row['restarts'] > 0 && $row['state'] !== self::STATE_RUNNING) {
                return false;
            }
        }

        return true;
    }

    protected function settle(): void
    {
        sleep(self::SETTLE_SECONDS);
    }

    /**
     * The probe ended with a container running and listening on nothing yet: wait until it binds, exits or restarts, or
     * the start budget is spent, then probe again, in full once something bound and else one look for the verdict.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>|null  $before
     * @return array<string, mixed>
     */
    protected function awaitSlowStart(array $report, ?array $before, ?DeployLogger $logger): array
    {
        $starting = [];
        foreach ((array) ($report['checks'] ?? []) as $check) {
            $starting = [...$starting, ...SilentPortCheck::starting(is_array($check) ? $check : null)];
        }
        $budget = $this->startPeriod();
        if ($starting === [] || $budget <= 0) {
            return $report;
        }

        $logger?->info(sprintf(
            'Still starting: %s listens on nothing yet; waiting up to %s more',
            implode(', ', array_unique($starting)),
            self::duration($budget)
        ));
        $deadline = $this->clock() + $budget;
        $bound = $this->waitUntilBound($report, $before, $logger, $deadline);

        $again = $this->observe(self::DEPLOY_TIMEOUT, $bound ? self::DEPLOY_ATTEMPTS : 1, self::DEPLOY_DELAY, true, $logger);

        return $again === null || isset($again['error']) ? $report : $again;
    }

    /**
     * Whether something bound before $deadline; a null silent check is a stopped or unreadable container, so nothing
     * to wait for.
     *
     * @param  array<string, mixed>  $report
     * @param  list<array<string, mixed>>|null  $before
     */
    private function waitUntilBound(array $report, ?array $before, ?DeployLogger $logger, int $deadline): bool
    {
        $bound = false;
        while ($this->clock() < $deadline) {
            $logger?->throwIfCancelled();
            $this->pause(min(self::START_POLL_SECONDS, max(1, $deadline - $this->clock())));
            $after = $this->containerSnapshot();
            if ($after !== null && self::restartLoopBetween($before, $after) !== null) {
                break;
            }
            $silent = $this->silentCheck((array) $report['ports']);
            if ($silent === null) {
                break;
            }
            if (SilentPortCheck::starting($silent) === []) {
                $bound = true;
                break;
            }
        }

        return $bound;
    }

    /** Seconds this account's app may take to bind its port, past the probe's own budget. */
    private function startPeriod(): int
    {
        try {
            $declared = $this->dind->userModel()->getDetails()[self::DETAIL_START_PERIOD] ?? null;
        } catch (\Throwable) {
            $declared = null;
        }

        return is_int($declared) ? max(0, $declared) : self::START_GRACE_SECONDS;
    }

    private static function duration(int $seconds): string
    {
        if ($seconds % 60 !== 0) {
            return "{$seconds} s";
        }
        $minutes = intdiv($seconds, 60);

        return $minutes === 1 ? '1 minute' : "{$minutes} minutes";
    }

    /**
     * @param  list<array<string, mixed>>  $ports
     * @return array<string, mixed>|null
     */
    protected function silentCheck(array $ports): ?array
    {
        return (new SilentPortCheck($this->dind))->check($ports);
    }

    protected function clock(): int
    {
        return time();
    }

    protected function pause(int $seconds): void
    {
        sleep($seconds);
    }

    /**
     * The run file's services that publish the port the site is routed to,
     * null when none can be told: then every looping service is the application.
     *
     * @return list<string>|null
     */
    private function appServices(): ?array
    {
        return AppPortServices::names($this->dind);
    }

    /**
     * Each container of the stack as `docker inspect` gives it; null when there is none or they could not be asked.
     *
     * @return list<array<string, mixed>>|null
     */
    protected function containerSnapshot(): ?array
    {
        try {
            $daemon = $this->dind->accountDaemon();
            $inspected = $daemon->containers(
                $daemon->stackContainerIds(true, true, timeout: self::RESTART_PROBE_TIMEOUT_SECONDS),
                self::RESTART_PROBE_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            Log::debug('Container snapshot could not run: '.self::trimReason($e->getMessage()));

            return null;
        }

        return $inspected === [] || in_array(null, $inspected, true) ? null : $inspected;
    }

    /**
     * Looping = Docker says `restarting`, or the restart count grew between the snapshots (exit 0 included). No earlier
     * snapshot, no count comparison: a sidecar kept from an older deploy may carry old restarts.
     *
     * @param  list<mixed>|null  $before  {@see containerSnapshot()}
     * @param  list<mixed>  $after
     * @param  list<string>|null  $appServices
     * @return array<string, mixed>|null
     */
    public static function restartLoopBetween(?array $before, array $after, ?array $appServices = null): ?array
    {
        $earlier = null;
        if ($before !== null) {
            $earlier = [];
            foreach (self::inspectRows($before) as $row) {
                $earlier[$row['name']] = $row['restarts'];
            }
        }

        $looping = [];
        $services = [];
        $appLoops = $appServices === null;
        foreach (self::inspectRows($after) as $row) {
            $grew = $earlier !== null && $row['restarts'] > ($earlier[$row['name']] ?? 0);
            if ($row['state'] !== self::STATE_RESTARTING && ! $grew) {
                continue;
            }
            $services[] = $row['service'] ?? $row['name'];
            $appLoops = $appLoops || in_array($row['service'], $appServices ?? [], true);
            $looping[] = self::loopingContainer($row);
        }

        if ($looping === []) {
            return null;
        }

        return self::restartLoopCheckFor($looping, $services, $appLoops);
    }

    /**
     * Docker resets ExitCode to 0 when it starts the container again, so the code is only the crash's while it is down.
     *
     * @param  array{name: string, service: ?string, state: string, exit: int, restarts: int}  $row
     */
    private static function loopingContainer(array $row): string
    {
        $down = in_array($row['state'], [self::STATE_RESTARTING, self::STATE_EXITED], true);

        return sprintf(
            '%s (%s, last exit %s, restarted %d time%s)',
            $row['service'] ?? $row['name'],
            $row['state'],
            $down ? (string) $row['exit'] : 'unknown',
            $row['restarts'],
            $row['restarts'] === 1 ? '' : 's'
        );
    }

    /**
     * With the app's services known and none looping, the backing service is named: Koillection's looping postgres
     * read "The application is restarting" and sent the reader to the wrong container.
     *
     * @param  list<string>  $looping
     * @param  list<string>  $services
     * @return array<string, mixed>
     */
    private static function restartLoopCheckFor(array $looping, array $services, bool $appLoops): array
    {
        if (! $appLoops) {
            return [
                'id' => self::CHECK_RESTART_LOOPING,
                'group' => 'runtime',
                'status' => CheckResult::STATUS_FAIL,
                'severity' => HealthCheck::SEVERITY_ERROR,
                'title' => 'A backing service is restarting: '.implode(', ', $looping).'.',
                'detail' => 'The application itself is not restarting, but what it needs from '
                    .implode(', ', $services).' may not be there.',
                'fix' => 'Read the container output of '.implode(', ', $services).'; the reason it exits is there.',
                'evidence' => ['restarting' => $looping],
            ];
        }

        return [
            'id' => self::CHECK_RESTART_LOOPING,
            'group' => 'runtime',
            'status' => CheckResult::STATUS_FAIL,
            'severity' => HealthCheck::SEVERITY_ERROR,
            'title' => 'The application is restarting, not running.',
            'detail' => 'Docker reports '.implode(', ', $looping)
                .'. It may answer between restarts, but it does not stay up.',
            'fix' => 'Read the container output; the reason it exits is there.'
                .' A process that exits 0 is restarted too, e.g. a command ending in a shell with no TTY.',
            'evidence' => ['restarting' => $looping],
        ];
    }

    /**
     * @param  list<mixed>  $inspected
     * @return list<array{name: string, service: ?string, state: string, exit: int, restarts: int}>
     */
    private static function inspectRows(array $inspected): array
    {
        $rows = [];
        foreach ($inspected as $container) {
            $name = is_array($container) ? self::stringOrNull($container['Name'] ?? null) : null;
            if ($name === null) {
                continue;
            }
            $state = is_array($container['State'] ?? null) ? $container['State'] : [];
            $rows[] = [
                'name' => ltrim($name, '/'),
                'service' => self::stringOrNull($container['Config']['Labels']['com.docker.compose.service'] ?? null),
                'state' => strtolower((string) ($state['Status'] ?? '')),
                'exit' => (int) ($state['ExitCode'] ?? 0),
                'restarts' => (int) ($container['RestartCount'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * The third line: is what answered the app? Warned, never failed: an engine stopping deploys over a page it did not
     * know gets one deploy. Only failures, not to bury the lines above; `info` is dim: worth recording, not colouring.
     *
     * @param  array<string, mixed>  $report
     */
    private function reportChecks(array $report, DeployLogger $logger): void
    {
        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (! is_array($check) || ($check['status'] ?? null) !== CheckResult::STATUS_FAIL) {
                continue;
            }

            $severity = (string) ($check['severity'] ?? HealthCheck::SEVERITY_ERROR);
            $line = ($severity === HealthCheck::SEVERITY_ERROR ? 'Check failed: ' : 'Check: ').$check['title'];
            foreach (['detail', 'fix'] as $key) {
                if (is_string($check[$key] ?? null) && $check[$key] !== '') {
                    $line .= ' '.$check[$key];
                }
            }
            $severity === HealthCheck::SEVERITY_INFO ? $logger->dim($line) : $logger->warn($line);
        }
    }

    /**
     * The second line: the app started, and can anyone get to it? Warned, not failed: an app that varies by Host is not
     * a broken deploy, and a check nobody can switch off is a check somebody switches off.
     *
     * @param  array<string, mixed>  $report
     */
    private function reportReachability(array $report, DeployLogger $logger): void
    {
        $reach = $report['domain'] ?? null;
        if (! is_array($reach)) {
            return;
        }

        if ($reach['verdict'] === self::REACH_OK) {
            $logger->ok(self::describeReach($reach));

            return;
        }

        if ($reach['verdict'] === self::REACH_SKIPPED) {
            $logger->dim(self::describeReach($reach));

            return;
        }

        $logger->warn(self::describeReach($reach));
    }

    /**
     * Probe the app and remember what it found: {@see report()} adds the log lines, the periodic sweep wants just this.
     * Null when the probe could not run at all, which is no verdict and is not remembered; no answer is one, and is.
     *
     * @return array<string, mixed>|null
     */
    public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2, bool $waitOnServerError = false, ?DeployLogger $deploy = null): ?array
    {
        try {
            $report = $this->check($timeout, $attempts, $delay, $waitOnServerError, $deploy);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('App health check failed to run: '.$e->getMessage());

            return null;
        }

        $this->remember($report);

        return $report;
    }

    /**
     * The checks that failed on this account's last observation, whatever their severity: what is worth telling anyone,
     * the sweep's wider question beside {@see errorCheckMessages()}, which is what stops a deploy reading as clean.
     *
     * @param  array<string, mixed>  $details  the account's details
     * @return list<array{id: string, severity: string, message: string}>
     */
    public static function failedChecks(array $details): array
    {
        $failures = [];
        foreach ((array) ($details[self::DETAIL_CHECKS] ?? []) as $failure) {
            if (! is_array($failure) || ! is_string($failure['id'] ?? null)) {
                continue;
            }
            $failures[] = [
                'id' => $failure['id'],
                'severity' => is_string($failure['severity'] ?? null)
                    ? $failure['severity']
                    : HealthCheck::SEVERITY_ERROR,
                'message' => is_string($failure['message'] ?? null) ? $failure['message'] : '',
            ];
        }

        return $failures;
    }

    /**
     * Whether a port answered the last observation with a 5xx: the app is up
     * and failing, and why is in what its container printed.
     *
     * @param  array<string, mixed>  $details  the account's details
     */
    public static function sawServerError(array $details): bool
    {
        foreach ((array) ($details[self::DETAIL_PORTS] ?? []) as $port) {
            if (is_array($port) && is_int($port['http_code'] ?? null) && $port['http_code'] >= self::SERVER_ERROR_FROM) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write the verdict onto the account, ports and status codes only; `checked` tells "nothing to probe" from "nothing
     * answered" later, when both have empty ports. Never throws: losing a diagnosis must not cost a deploy that worked.
     *
     * @param  array{healthy: bool|null, ports: list<array<string, mixed>>, error?: string}  $report
     */
    private function remember(array $report): void
    {
        try {
            $user = $this->dind->userModel();
            $reach = $report['domain'] ?? null;
            $user->setDetails([
                self::DETAIL_CHECKED => $report['healthy'] !== null,
                self::DETAIL_HEALTHY => $report['healthy'],
                self::DETAIL_REACHABLE => is_array($reach) ? $reach['verdict'] : null,
                self::DETAIL_REACHABLE_CODE => is_array($reach) && is_int($reach['http_code'] ?? null) ? $reach['http_code'] : null,
                self::DETAIL_SERVING => $report['serving'] ?? null,
                self::DETAIL_CHECKS => self::failuresOf($report),
                self::DETAIL_PORTS => array_map(
                    static fn (array $result): array => [
                        'port' => $result['port'],
                        'status' => $result['status'],
                        'http_code' => $result['http_code'],
                    ],
                    $report['ports']
                ),
            ]);
            $user->save();
        } catch (\Throwable $e) {
            Log::debug('Could not record the app health verdict: '.$e->getMessage());
        }
    }

    /**
     * The failing checks in a report, flattened to what is worth remembering.
     *
     * @param  array<string, mixed>  $report
     * @return list<array{id: string, severity: string, message: string}>
     */
    private static function failuresOf(array $report): array
    {
        $failures = [];
        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (! is_array($check) || ($check['status'] ?? null) !== CheckResult::STATUS_FAIL) {
                continue;
            }
            $failures[] = [
                'id' => (string) ($check['id'] ?? ''),
                'severity' => (string) ($check['severity'] ?? HealthCheck::SEVERITY_ERROR),
                'message' => trim(implode(' ', array_filter([
                    $check['title'] ?? null,
                    $check['detail'] ?? null,
                    $check['fix'] ?? null,
                ], is_string(...)))),
            ];
        }

        return $failures;
    }

    /**
     * Checks failed badly enough to call a deploy not entirely working. Errors only: an empty project's welcome page is a
     * warning, and marking every empty account partial would teach everyone to ignore the field.
     *
     * @param  array<string, mixed>  $details  the account's details
     * @return list<Finding>
     */
    public static function errorCheckFindings(array $details): array
    {
        $findings = [];
        foreach ((array) ($details[self::DETAIL_CHECKS] ?? []) as $failure) {
            if (! is_array($failure) || ($failure['severity'] ?? null) !== HealthCheck::SEVERITY_ERROR) {
                continue;
            }
            $message = $failure['message'] ?? '';
            if (is_string($message) && trim($message) !== '') {
                $findings[] = new Finding(self::CODE_CHECK_FAILED, trim($message), ['check' => (string) ($failure['id'] ?? '')]);
            }
        }

        return $findings;
    }

    /**
     * What should stop a deploy being called clean, in reading order: one answer for the create path, `User::rebuild()`
     * and `ProjectRebuild::recordSucceeded()`, which had diverged. Not answering leads: it explains the checks after.
     *
     * @param  array<string, mixed>  $details
     * @return list<Finding>
     */
    public static function servingWarnings(array $details): array
    {
        $warnings = self::nothingAnswered($details) ? [new Finding(self::CODE_NOT_ANSWERING, self::NOT_ANSWERING)] : [];
        $edge = self::edgeFailure($details);
        if ($edge !== null) {
            $warnings[] = $edge;
        }

        return array_merge($warnings, self::errorCheckFindings($details));
    }

    /**
     * The app answers inside the account and the site does not: a gateway error at the domain, or a refused connection.
     * Any other difference stays a log line ({@see reportReachability()}): an app that varies by Host is not broken.
     *
     * @param  array<string, mixed>  $details
     */
    public static function edgeFailure(array $details): ?Finding
    {
        $verdict = $details[self::DETAIL_REACHABLE] ?? null;
        $code = $details[self::DETAIL_REACHABLE_CODE] ?? null;
        $gateway = $verdict === self::REACH_DIFFERS && in_array($code, self::EDGE_GATEWAY_ERRORS, true);
        if (! $gateway && $verdict !== self::REACH_UNREACHABLE) {
            return null;
        }
        if (! self::anyPortAnswered($details)) {
            return null;
        }

        return $gateway
            ? new Finding(
                self::CODE_SITE_GATEWAY_ERROR,
                "The application answers inside the account, but the site answers {$code} through the webserver.",
                ['http_code' => $code],
            )
            : new Finding(
                self::CODE_SITE_UNREACHABLE,
                'The application answers inside the account, but the webserver did not accept a connection for the site.',
            );
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private static function anyPortAnswered(array $details): bool
    {
        foreach ((array) ($details[self::DETAIL_PORTS] ?? []) as $port) {
            $answer = is_array($port) ? ($port['http_code'] ?? null) : null;
            if (is_int($answer) && $answer > 0 && $answer < self::SERVER_ERROR_FROM) {
                return true;
            }
        }

        return false;
    }

    /**
     * The account's container was down at the last observation: nothing was probed, as nothing in it could answer.
     *
     * @param  array<string, mixed>  $details  the account's details
     */
    public static function containerWasDown(array $details): bool
    {
        return ($details[self::DETAIL_HEALTHY] ?? null) === false
            && ($details[self::DETAIL_SERVING] ?? null) === CheckRunner::SERVING_STOPPED
            && ($details[self::DETAIL_PORTS] ?? null) === [];
    }

    /**
     * Did every probed port give no HTTP answer at all? Strict on purpose: one dead port of three is a log line; only
     * nothing answering is sure enough to change a deploy's report. A 5xx is an answer, `no-server-error`'s to report.
     *
     * @param  array<string, mixed>  $details
     */
    public static function nothingAnswered(array $details): bool
    {
        if (($details[self::DETAIL_CHECKED] ?? false) !== true) {
            return false;
        }
        if (($details[self::DETAIL_HEALTHY] ?? null) !== false) {
            return false;
        }

        $ports = $details[self::DETAIL_PORTS] ?? [];
        if (! is_array($ports) || $ports === []) {
            return false;
        }

        foreach ($ports as $port) {
            if (is_array($port) && (($port['status'] ?? null) === self::STATUS_OK || is_int($port['http_code'] ?? null))) {
                return false;
            }
        }

        return true;
    }

    /**
     * One human-readable line for a single port's result. A 5xx answered: "did not answer" would send the reader to a
     * dead port instead of a broken app.
     *
     * @param  array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}  $result
     */
    public static function describe(array $result): string
    {
        $scheme = $result['scheme'] ?? 'http';
        $target = "{$scheme}://127.0.0.1:{$result['port']}/";

        if ($result['status'] === self::STATUS_OK) {
            return "Health check: {$target} answered {$result['detail']} ({$result['time']}s)";
        }

        if ($result['http_code'] !== null) {
            return "Health check: {$target} answered {$result['detail']} — the application is failing";
        }

        return "Health check: {$target} did not answer — {$result['detail']}";
    }

    /**
     * The probe, one script in the account container (one exec, not one per port), dialling 127.0.0.1 so DNS, TLS and
     * the proxy cannot colour it, yet as a visitor: an app validating Host refuses `Host: 127.0.0.1`, serving its name.
     *
     * @param  list<int>  $ports
     * @param  list<int>  $httpsPorts
     */
    public static function probeScript(
        array $ports,
        int $timeout = 5,
        int $attempts = 3,
        int $delay = 2,
        ?string $domain = null,
        bool $waitOnServerError = false,
        array $httpsPorts = []
    ): string {
        $secureList = implode(' ', array_map('intval', $httpsPorts));
        $timeout = max(1, $timeout);
        $attempts = max(1, $attempts);
        $delay = max(0, $delay);
        $sample = ProbedResponse::SAMPLE_BYTES;
        $list = implode(' ', array_map('intval', $ports));
        $domain = $domain !== null && trim($domain) !== '' ? strtolower(trim($domain)) : null;
        $hostHeader = self::visitorHeaders($domain);
        $ownName = $domain !== null ? ' || [ "$host" = '.escapeshellarg($domain).' ]' : '';
        $waitCodes = $waitOnServerError ? '000|500|502|503|504' : '000|502|503|504';

        return self::probeScriptFor($secureList, $timeout, $attempts, $delay, $sample, $list, $hostHeader, $ownName, $waitCodes);
    }

    /**
     * HTTPS only when HTTP got nothing back, so an app terminating TLS is not down, and first for $secureList (nginx
     * answers plain http there with a 400 that would pass for the app); `-k`: a self-signed cert is not the question.
     */
    private static function probeScriptFor(
        string $secureList,
        int $timeout,
        int $attempts,
        int $delay,
        int $sample,
        string $list,
        string $hostHeader,
        string $ownName,
        string $waitCodes
    ): string {
        return <<<SH
set -u

# Echoes "CODE TIME<tab>ERROR<tab>BODY". CODE is 000 when nothing answered.
# BODY is the first {$sample} bytes, base64 so it survives a line-oriented
# protocol: a page containing a tab or a newline would otherwise be read as
# three more ports. It is what tells the engine's own placeholder apart from
# an application, which no status code can.
# Follows a redirect, because the landing page is what a visitor gets and is
# where the failure usually is: Firefly III answers / with a 302 to /login and
# /login with a 500, and probing only / reported twelve passing checks on a
# site that served an error page to everyone.
#
# Two shapes are followed, and both stay on loopback. A relative `Location:
# /login` is the common one. An absolute one is followed only when it points
# back at this same 127.0.0.1 port -- which is what a framework that builds
# its redirect from the request host produces, and what Firefly does: it
# answers an http request with `Location: https://127.0.0.1:8000/login`.
# Following the relative form alone still read 302 on a site whose login page
# was a 500, so the absolute-but-local case has to be here too.
#
# The scheme of that Location is deliberately ignored, and this is the part
# that would have made things worse rather than better. Firefly names https
# because the engine sets FORCE_SSL and it believes it is behind a proxy, but
# the port the probe dials is the container's own Apache, which speaks plain
# http -- following the scheme it named would fail the TLS handshake and
# report 000 for a healthy site. The path and the port are taken from the
# Location; the scheme is the one that already got an answer. An app that
# really terminates TLS answered nothing on http, and the retry above is
# already probing it on https, which is then the scheme carried in here.
#
# With the project's domain as the Host header, an app that builds its
# redirect from the request names that domain instead, so it counts as local
# too -- the connection still goes to 127.0.0.1.
#
# Anything else is not followed. An application that canonicalises to its own
# public name -- WordPress on every site -- must not send this probe out of
# the container and onto the internet; that case is already understood one
# layer up, where a local redirect against a remote 200 reads as healthy.
probe() {
    err_file=\$(mktemp)
    body_file=\$(mktemp)
    head_file=\$(mktemp)
    scheme=\$1
    path=/
    hops=0
    while :; do
        out=\$(curl -sS -k {$hostHeader}-o "\$body_file" -D "\$head_file" -w '%{http_code} %{time_total}' --max-time {$timeout} "\$scheme://127.0.0.1:\$2\$path" 2>"\$err_file") || true
        code=\${out%% *}
        case "\$code" in
            30[12378])
                location=\$(sed -n 's/^[Ll][Oo][Cc][Aa][Tt][Ii][Oo][Nn]:[[:space:]]*//p' "\$head_file" | tr -d '\r\n' | head -n 1)
                # Path-relative, made absolute against the current path first:
                # Dolibarr answers / with `Location: install/index.php`.
                case "\$location" in
                    /*|*:*|'') ;;
                    *) base=\${path%%\?*}; location=\${base%/*}/\$location ;;
                esac
                follow=0
                new_path=/
                case "\$location" in
                    /*)
                        new_path=\$location
                        follow=1
                        ;;
                    *)
                        rest=\${location#*//}
                        # `rest` is the whole string when there was no '//': a
                        # scheme-relative or malformed Location, not an address.
                        if [ -n "\$rest" ] && [ "\$rest" != "\$location" ]; then
                            authority=\${rest%%/*}
                            case "\$rest" in
                                */*) new_path=/\${rest#*/} ;;
                            esac
                            host=\${authority%%:*}
                            port=\${authority#*:}
                            [ "\$port" = "\$authority" ] && port=
                            if { [ "\$host" = 127.0.0.1 ] || [ "\$host" = localhost ]{$ownName}; } \
                                && { [ -z "\$port" ] || [ "\$port" = "\$2" ]; }; then
                                follow=1
                            fi
                        fi
                        ;;
                esac
                if [ "\$follow" = 1 ]; then
                    hops=\$((hops + 1))
                    if [ "\$hops" -le 5 ]; then
                        path=\$new_path
                        continue
                    fi
                fi
                ;;
        esac
        break
    done
    # Last: the path that answered, after the redirects above.
    printf '%s\\t%s\\t%s\\t%s' "\${out:-000 0}" "\$(tr -d '\\r\\n' <"\$err_file" | sed -e 's/^curl: ([0-9]*) //' -e 's/ after [0-9]* ms:.*//' | cut -c1-120)" "\$(head -c {$sample} "\$body_file" | base64 | tr -d '\\r\\n')" "\$(printf '%s' "\$path" | tr -d '\\t\\r\\n' | cut -c1-512)"
    rm -f "\$err_file" "\$body_file" "\$head_file"
}

for port in {$list}; do
    attempt=1
    while :; do
        scheme=http
        other=https
        case " {$secureList} " in
            *" \$port "*) scheme=https; other=http ;;
        esac
        result=\$(probe "\$scheme" "\$port")
        if [ "\${result%% *}" = "000" ]; then
            second=\$(probe "\$other" "\$port")
            if [ "\${second%% *}" != "000" ]; then
                scheme=\$other
                result=\$second
            fi
        fi
        # A proxy bundled in the image (Caddy, nginx) binds the port at once
        # and answers 502-504 until its backend is up: keep waiting on those.
        # A deploy waits on 500 too: the app may still be warming up.
        case "\${result%% *}" in
            {$waitCodes}) ;;
            *) break ;;
        esac
        [ "\$attempt" -ge {$attempts} ] && break
        attempt=\$((attempt + 1))
        sleep {$delay}
    done
    printf '%s\\t%s\\t%s\\n' "\$port" "\$scheme" "\$result"
done
exit 0
SH;
    }

    /**
     * curl flags for the headers the account vhost adds for a visitor on https: an app insisting on a secure request
     * (ASP.NET antiforgery in Squidex) answers 500 without them, and one trusting its proxy needs the client address.
     */
    public static function visitorHeaders(?string $domain): string
    {
        if ($domain === null) {
            return '';
        }

        return implode(' ', array_map(
            static fn (string $header): string => '-H '.escapeshellarg($header),
            [
                "Host: {$domain}", 'X-Forwarded-Proto: https', "X-Forwarded-Host: {$domain}", 'X-Forwarded-Port: 443',
                'X-Forwarded-For: '.self::PROBE_CLIENT, 'X-Real-IP: '.self::PROBE_CLIENT,
            ]
        )).' ';
    }

    /**
     * The client address the probe claims: a documentation address (RFC 5737),
     * so the app answers an outside visitor, never a loopback "local" client.
     */
    private const PROBE_CLIENT = '192.0.2.1';

    /**
     * Ceiling for the whole probe: every port may burn both schemes on every
     * attempt, plus the sleeps between them.
     *
     * @param  list<int>  $ports
     */
    public static function timeBudget(array $ports, int $timeout = 5, int $attempts = 3, int $delay = 2): int
    {
        $perPort = ($attempts * (2 * max(1, $timeout) + max(0, $delay)));

        return 30 + count($ports) * $perPort;
    }

    /**
     * @param  list<int>  $ports
     * @return list<array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>
     */
    public static function parseProbeOutput(string $raw, array $ports): array
    {
        $seen = [];
        foreach (preg_split('/\r?\n/', $raw) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $fields = explode("\t", $line);
            if (count($fields) < 3) {
                continue;
            }
            $port = (int) trim($fields[0]);
            if ($port <= 0) {
                continue;
            }
            $measured = preg_split('/\s+/', trim($fields[2])) ?: [];
            $seen[$port] = self::result(
                $port,
                trim($fields[1]),
                (int) ($measured[0] ?? 0),
                (float) ($measured[1] ?? 0),
                isset($fields[3]) ? trim($fields[3]) : '',
                isset($fields[4]) ? trim($fields[4]) : '',
                isset($fields[5]) ? trim($fields[5]) : ''
            );
        }

        return self::inPortOrder($seen, $ports);
    }

    /**
     * @param  array<int, array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>  $seen
     * @param  list<int>  $ports
     * @return list<array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>
     */
    private static function inPortOrder(array $seen, array $ports): array
    {
        $results = [];
        foreach ($ports as $port) {
            $port = (int) $port;
            $results[] = $seen[$port] ?? self::blank($port, 'the probe returned no result for this port');
        }

        return $results;
    }

    /**
     * True only when every probed port answered. Null when nothing was
     * probed, so "not applicable" never reads as "down".
     *
     * @param  list<array{status: string}>  $results
     */
    public static function summarize(array $results): ?bool
    {
        if ($results === []) {
            return null;
        }
        foreach ($results as $result) {
            if ($result['status'] !== self::STATUS_OK) {
                return false;
            }
        }

        return true;
    }

    /**
     * `body` and `path` (where the redirects ended) are evidence for the checks, not the caller: a page of somebody's
     * application does not belong in an API response about whether it is up.
     *
     * @return array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}
     */
    private static function result(
        int $port,
        string $scheme,
        int $code,
        float $time,
        string $error,
        string $bodyBase64 = '',
        string $path = ''
    ): array {
        if ($code === 0) {
            return self::blank($port, $error !== '' ? $error : 'no response');
        }

        $status = $code >= self::SERVER_ERROR_FROM ? self::STATUS_FAIL : self::STATUS_OK;

        return [
            'port' => $port,
            'scheme' => $scheme !== '' ? $scheme : 'http',
            'status' => $status,
            'http_code' => $code,
            'time' => round($time, 3),
            'detail' => "HTTP {$code}",
            'body' => self::decodeSample($bodyBase64),
            'path' => str_starts_with($path, '/') ? $path : HealthCheck::DEFAULT_PATH,
        ];
    }

    /** The probe's base64 body sample, or '' for anything unusable. */
    private static function decodeSample(string $encoded): string
    {
        if ($encoded === '') {
            return '';
        }
        $decoded = base64_decode($encoded, true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * @return array{port: int, scheme: null, status: string, http_code: null, time: null, detail: string}
     */
    private static function blank(int $port, string $detail): array
    {
        return [
            'port' => $port,
            'scheme' => null,
            'status' => self::STATUS_FAIL,
            'http_code' => null,
            'time' => null,
            'detail' => $detail,
        ];
    }

    /**
     * First line of an exception message, capped: a wedged account container returns a multi-line OCI runtime error,
     * and the whole of it in a table cell or a deploy log line hides everything around it.
     */
    public static function trimReason(string $message): string
    {
        $first = trim((string) (preg_split('/\r?\n/', trim($message))[0] ?? ''));
        if ($first === '') {
            return 'unknown error';
        }

        return mb_strlen($first) > 160 ? mb_substr($first, 0, 157).'...' : $first;
    }
}
