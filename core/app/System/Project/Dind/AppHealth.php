<?php

namespace App\System\Project\Dind;

use App\System\Project\Dind;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Health\CheckResult;
use App\Lib\Deploy\Health\CheckRunner;
use App\Lib\Deploy\Health\HealthCheck;
use App\Lib\Deploy\Health\ProbedResponse;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Does the deployed application actually answer?
 *
 * A deploy turns green the moment `docker compose up` returns, which says
 * nothing about whether the application came up behind it. This probes the
 * ports the app publishes and reports what answered — the same gap
 * {@see AppPortAlignment} exists to close, checked rather than
 * inferred.
 *
 * Two questions, kept apart because they fail for different reasons and have
 * different answers:
 *
 * - **Did the application come up?** curl runs *inside the account container*
 *   against 127.0.0.1, so the domain, DNS, TLS and nginx-proxy cannot colour
 *   the result. A red answer here means the application is broken.
 * - **Can anyone reach it?** the same request through the webserver, from the
 *   host, with the project's own Host header. A red answer here means the
 *   routing is broken while the application is fine.
 *
 * The second existed nowhere for a long time, and the cost was a proxy that
 * served every domain from one project while four deploys in a row reported
 * success: every container was healthy, and nothing ever asked the question a
 * visitor asks. `healthy` still means only the first -- it is persisted and
 * served over the API, so a routing failure must not silently redefine it --
 * and `reachable` carries the second.
 *
 * Advisory only. Nothing in the deploy pipeline fails on it — plenty of
 * legitimate deploys (workers, queue consumers, gRPC-only services) answer
 * nothing on HTTP.
 */
class AppHealth
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAIL = 'fail';

    /** Docker's word for a container that keeps dying and coming back. */
    private const STATE_RESTARTING = 'restarting';

    /** The other half of that cycle, while the backoff waits. */
    private const STATE_EXITED = 'exited';

    /** The check {@see restartLoopCheck()} adds when it finds one. */
    public const CHECK_RESTART_LOOPING = 'app-restart-looping';

    /** Short: the deploy is already over and one `compose ps` is all this is. */
    private const RESTART_PROBE_TIMEOUT_SECONDS = 20;

    /** The domain answers with what the application answers. */
    public const REACH_OK = 'ok';

    /** The webserver answered its own 404: no vhost is loaded for this name. */
    public const REACH_NOT_ROUTED = 'not_routed';

    /** Something answered, but not this application. */
    public const REACH_DIFFERS = 'differs';

    /** Nothing accepted the connection at all. */
    public const REACH_UNREACHABLE = 'unreachable';

    /** No domain, or no answering port to compare against. */
    public const REACH_SKIPPED = 'skipped';

    /**
     * From here up, the application answered but is failing. This is the
     * "green deploy behind a 502" case, which is exactly what the check is
     * for — a port that accepts a connection is not the same as an app that
     * works. 4xx stays healthy: plenty of apps legitimately 404 on `/`.
     */
    private const SERVER_ERROR_FROM = 500;

    private Dind $dind;

    public function __construct(Dind $dind)
    {
        $this->dind = $dind;
    }

    /**
     * Published ports worth probing, datastores already filtered out by
     * {@see DetectAppPort} — a deploy's Postgres sidecar answering on 5432
     * says nothing about the application.
     *
     * @return list<int>
     */
    public function ports(): array
    {
        $ports = DetectAppPort::detectAllPorts($this->dind->userAppComposeFileToRun())['all'];

        return array_values(array_map('intval', $ports));
    }

    /**
     * Probe every published port and report what answered.
     *
     * `healthy` is null when there was nothing to probe — that is "not
     * applicable", never "unhealthy".
     *
     * `error` is present only when the check could not be carried out at all;
     * a port that simply did not answer is a normal result, not an error.
     *
     * @return array{healthy: bool|null, ports: list<array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}>, error?: string}
     */
    public function check(int $timeout = 5, int $attempts = 3, int $delay = 2): array
    {
        try {
            $ports = $this->ports();
        } catch (\Exception $e) {
            // Resolving the compose file touches the account's filesystem
            // through a subprocess. If that is what broke we cannot tell
            // whether the app is up — which is a failed check, not a 500, and
            // not the same as "nothing to probe".
            return [
                'healthy' => false,
                'ports' => [],
                'error' => 'could not read the application compose file: ' . self::trimReason($e->getMessage()),
            ];
        }

        if ($ports === []) {
            return ['healthy' => null, 'ports' => []];
        }

        try {
            $raw = $this->dind->shell()->execQuiet(
                ['bash', '-c', self::probeScript($ports, $timeout, $attempts, $delay)],
                [],
                self::timeBudget($ports, $timeout, $attempts, $delay)
            );
        } catch (\Exception $e) {
            // Could not reach the account container at all — a wedged sysbox
            // container is the case seen in practice. Reporting each port as
            // "did not answer" would be a lie: we never asked. The ports list
            // stays empty and the reason goes in `error`.
            return [
                'healthy' => false,
                'ports' => [],
                'error' => 'could not run the probe: ' . self::trimReason($e->getMessage()),
            ];
        }

        $results = self::parseProbeOutput($raw, $ports);
        $verdict = $this->runChecks($results);

        $checks = $verdict['checks'];
        $looping = $this->restartLoopCheck($results);
        if ($looping !== null) {
            $checks[] = $looping;
        }

        return [
            'healthy' => self::summarize($results),
            // The third question, beside "did it come up" and "can anyone
            // reach it": is what answered the application, or is it us?
            'serving' => $verdict['serving'],
            'ports' => self::withoutBodies($results),
            'domain' => $this->reachability($results, $timeout),
            'checks' => $checks,
        ];
    }

    /**
     * When nothing answered, say whether the application is dying and being
     * restarted -- because otherwise nothing does.
     *
     * Every check the runtime brings is asked *of a response*, so when no
     * port answers there is no response to ask about and the list comes back
     * empty. The account is then reported as `healthy: false`, `serving:
     * unknown`, `health_failed_checks: []` -- and that is indistinguishable
     * from a queue worker that publishes no port and is working perfectly.
     * The deploy even says so out loud: "a worker or queue-only app can
     * ignore this".
     *
     * Two applications in the supported-apps series were not workers.
     * PocketBase's entrypoint runs the binary with no subcommand, so it
     * printed its help and exited 0 seven times in ninety seconds; Miniflux
     * exited on `dial tcp [::1]:5432: connect: connection refused` because
     * nothing had provisioned the PostgreSQL it requires. Both were reported
     * with an empty check list and a message inviting the reader to dismiss
     * it.
     *
     * Docker knows the difference and was never asked. A worker sits in
     * `running`; these sit in `restarting`.
     *
     * Only asked when nothing answered: a service that publishes no port and
     * restarts occasionally is not this, and an application that is serving
     * has already answered the question.
     *
     * @param list<array<string, mixed>> $results as {@see parseProbeOutput} produced them
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
            // Quiet: `report()` runs with a deploy logger attached, and the
            // streaming variant would put a page of compose JSON in the log.
            $raw = $this->dind->shell()->execAsUserQuiet(
                $this->dind->userAppComposeCommand(['ps', '--format', 'json', '--all']),
                [],
                self::RESTART_PROBE_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            // The same rule the checks themselves follow: a verdict that
            // could not be produced costs nothing that was already gathered.
            Log::debug('Restart-loop probe could not run: ' . self::trimReason($e->getMessage()));

            return null;
        }

        return self::restartLoopFrom($raw);
    }

    /**
     * The decision itself, over `docker compose ps --format json --all`
     * output: one JSON object per line.
     *
     * Separated from fetching it so it can be tested without an account, a
     * daemon or a container -- the point being that the difference between a
     * worker and a crash-loop is already in that output, and all this does is
     * read it.
     *
     * @return array<string, mixed>|null
     */
    public static function restartLoopFrom(string $composePsJson): ?array
    {
        $restarting = [];
        foreach (preg_split('/\R/', trim($composePsJson)) ?: [] as $line) {
            $row = json_decode(trim($line), true);
            if (!is_array($row) || !self::isCrashing($row)) {
                continue;
            }
            $name = self::stringOrNull($row['Service'] ?? null) ?? self::stringOrNull($row['Name'] ?? null);
            $status = self::stringOrNull($row['Status'] ?? null);
            $restarting[] = trim(($name ?? 'a service') . ($status === null ? '' : " ({$status})"));
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
            'detail' => 'Docker reports ' . implode(', ', $restarting)
                . '. Nothing answered on any published port because the process keeps exiting.',
            'fix' => 'Read the container output with container_service_logs; the reason it exits is there.',
            'evidence' => ['restarting' => $restarting],
        ];
    }

    /**
     * A container in a crash loop, whichever half of the cycle it is in.
     *
     * `restarting` is only one of the two states such a container occupies:
     * it alternates between that and `exited` while Docker's backoff waits,
     * so the same loop reads one way or the other depending purely on when
     * the question is asked. Wekan showed it -- the check fired during the
     * deploy and was persisted onto the account, and a standalone
     * `app_health_check` a minute later reported no checks at all, on the
     * same container, still crashing.
     *
     * A clean `exited` with status 0 is not this: a one-shot job that
     * finished is allowed to have finished. A non-zero exit is a crash.
     *
     * @param array<string, mixed> $row one `docker compose ps --format json` row
     */
    private static function isCrashing(array $row): bool
    {
        $state = $row['State'] ?? null;
        if ($state === self::STATE_RESTARTING) {
            return true;
        }

        return $state === self::STATE_EXITED && (int) ($row['ExitCode'] ?? 0) !== 0;
    }

    /**
     * Ask the application the checks its runtime brings.
     *
     * Never throws. A verdict that could not be produced is one missing
     * section of a report, and losing it must not cost the port results that
     * were gathered successfully.
     *
     * @param list<array<string, mixed>> $results as {@see parseProbeOutput} produced them
     * @return array{serving: string, checks: list<array<string, mixed>>}
     */
    private function runChecks(array $results): array
    {
        try {
            $details = $this->dind->userModel()->getDetails();
            $runtime = self::stringOrNull($details[self::DETAIL_RUNTIME] ?? null);

            return CheckRunner::for($runtime, self::declaredChecks($details))
                ->run(self::firstResponse($results), $this->dind->userAppDirPath());
        } catch (\Throwable $e) {
            Log::debug('Health checks could not run: ' . self::trimReason($e->getMessage()));

            return ['serving' => CheckRunner::SERVING_UNKNOWN, 'checks' => []];
        }
    }

    /**
     * The checks the deployed platform's manifest added on top of its
     * runtime's group.
     *
     * Read from the manifest rather than frozen onto the account, because a
     * check list is a property of the recipe and not of the deploy: editing a
     * manifest should change what the next health check asks without every
     * account that already deployed having to be redeployed first.
     *
     * @param array<string, mixed> $details
     * @return list<string>
     */
    private static function declaredChecks(array $details): array
    {
        $platform = self::stringOrNull($details[self::DETAIL_PLATFORM] ?? null);
        if ($platform === null) {
            return [];
        }

        foreach (\App\Lib\Deploy\Platform\PlatformRegistry::all() as $manifest) {
            if ($manifest->id === $platform) {
                return $manifest->checks;
            }
        }

        return [];
    }

    /**
     * The response the checks are asked about: the first port that answered
     * at all.
     *
     * Answered, not passed. A 5xx is a failed port result and is precisely
     * what `no-server-error` exists to describe -- skipping it would leave
     * the one check written for that case unable to see it.
     *
     * @param list<array<string, mixed>> $results
     */
    private static function firstResponse(array $results): ProbedResponse
    {
        foreach ($results as $result) {
            $code = $result['http_code'] ?? null;
            if (is_int($code) && $code > 0) {
                $scheme = is_string($result['scheme'] ?? null) ? $result['scheme'] : 'http';

                return new ProbedResponse(
                    $code,
                    is_string($result['body'] ?? null) ? $result['body'] : '',
                    "{$scheme}://127.0.0.1:{$result['port']}/",
                    is_numeric($result['time'] ?? null) ? (float) $result['time'] : 0.0
                );
            }
        }

        return ProbedResponse::none();
    }

    /**
     * The port results as a caller sees them.
     *
     * The body sample is evidence for the checks. A page out of somebody's
     * application has no business in an API response about whether that
     * application is up, and it is the one field here that could be large.
     *
     * @param list<array<string, mixed>> $results
     * @return list<array<string, mixed>>
     */
    private static function withoutBodies(array $results): array
    {
        return array_map(static function (array $result): array {
            unset($result['body']);

            return $result;
        }, $results);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Ask the question a visitor asks: does this project's domain serve this
     * project's application?
     *
     * Compared by content, not by status code. The outage this exists for
     * answered 200 through the proxy and 200 in the container while serving a
     * different customer's site -- statuses matched, and only the bytes told
     * them apart. A hash of the first 2KB is enough to separate two documents
     * without carrying either of them back.
     *
     * Never throws: a check that cannot run reports that it could not.
     *
     * @param list<array<string, mixed>> $ports as {@see check()} produced them
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public function reachability(array $ports, int $timeout = 5): array
    {
        try {
            return $this->probeReachability($ports, $timeout);
        } catch (\Throwable $e) {
            return self::reachSkipped(null, 'the check could not run: ' . self::trimReason($e->getMessage()));
        }
    }

    /**
     * @param list<array<string, mixed>> $ports
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    private function probeReachability(array $ports, int $timeout): array
    {
        $domain = $this->dind->userModel()->getMainDomain()?->domain;
        if ($domain === null || $domain === '') {
            return self::reachSkipped(null, 'the project has no domain yet');
        }

        $served = self::firstAnsweringPort($ports);
        if ($served === null) {
            // Nothing answered in the container, which report() has already
            // said. Routing cannot be judged against a silent application, and
            // saying "unreachable" here would blame the proxy for the app.
            return self::reachSkipped($domain, 'the application is not answering, so there is nothing to compare');
        }

        // The address the vhost listens on, which is the address a visitor
        // arrives at -- and under NAT the local one, not the public one.
        $ip = User::defaultBindIpAddresses()['ipv4'][0] ?? '127.0.0.1';

        $app = self::parseFingerprint($this->dind->shell()->execQuiet(
            ['bash', '-c', self::appProbeScript((string) $served['scheme'], (int) $served['port'], $timeout, $domain)],
            [],
            $timeout + 10
        ));

        return self::awaitRoute(
            $domain,
            fn (): array => self::parseFingerprint($this->dind->system()->execOnHost(
                ['bash', '-c', self::edgeProbeScript($domain, $ip, $timeout)],
            )),
            $app
        );
    }

    /** Seconds between edge re-probes while a just-reloaded vhost settles. */
    public const EDGE_RETRY_DELAYS = [1, 2, 4, 8, 15];

    /**
     * Probe the edge and compare, re-probing while it looks unrouted: the
     * webserver reload the deploy just issued may not have taken effect yet.
     *
     * @param callable(): array{code: int, hash: string, default404: bool} $probeEdge
     * @param array{code: int, hash: string, default404: bool} $app
     * @param (callable(int): void)|null $sleep
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function awaitRoute(string $domain, callable $probeEdge, array $app, ?callable $sleep = null): array
    {
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        $verdict = self::compareFingerprints($domain, $probeEdge(), $app);

        foreach (self::EDGE_RETRY_DELAYS as $delay) {
            if (!in_array($verdict['verdict'], [self::REACH_NOT_ROUTED, self::REACH_UNREACHABLE], true)) {
                break;
            }
            $sleep($delay);
            $verdict = self::compareFingerprints($domain, $probeEdge(), $app);
        }

        return $verdict;
    }

    /**
     * @param array{code: int, hash: string, default404: bool} $edge
     * @param array{code: int, hash: string, default404: bool} $app
     * @return array{verdict: string, domain: ?string, http_code: ?int, detail: string}
     */
    public static function compareFingerprints(string $domain, array $edge, array $app): array
    {
        if ($edge['code'] === 0) {
            return [
                'verdict' => self::REACH_UNREACHABLE,
                'domain' => $domain,
                'http_code' => null,
                'detail' => 'the webserver did not accept the connection',
            ];
        }

        if ($edge['default404']) {
            return [
                'verdict' => self::REACH_NOT_ROUTED,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => 'the webserver answered its own 404 page, so no vhost is loaded for this domain',
            ];
        }

        if ($edge['hash'] === $app['hash'] && $edge['hash'] !== '') {
            return [
                'verdict' => self::REACH_OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => (string) $edge['code'],
            ];
        }

        // The bodies differ because the application canonicalises: probed on
        // 127.0.0.1 it redirects to the address it was installed under, and
        // through the domain it serves the page. That is WordPress on every
        // site, and it is the healthy shape -- the request that matters
        // succeeded. Comparing content is still what catches a domain serving
        // somebody else's application, which answers 200 at both ends.
        if ($edge['code'] >= 200 && $edge['code'] < 300 && $app['code'] >= 300 && $app['code'] < 400) {
            return [
                'verdict' => self::REACH_OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'] . ' (the application redirects to its own address when probed locally)',
            ];
        }

        // The application does not reproduce its own bytes, so a difference
        // between it and the edge says nothing. Comparing what is left --
        // the status code -- still catches the domain that answers with
        // somebody else's error page, and stops reporting every CSRF-token
        // application on the host as unreachable.
        if (!self::bodyIsReproducible($app) && $edge['code'] === $app['code']) {
            return [
                'verdict' => self::REACH_OK,
                'domain' => $domain,
                'http_code' => $edge['code'],
                'detail' => $edge['code'] . ' (the application varies its own response, so only the status was compared)',
            ];
        }

        // Deliberately not called "another project's site": an application
        // that varies by Host -- a framework redirecting to its configured
        // APP_URL -- lands here too, and is not broken.
        return [
            'verdict' => self::REACH_DIFFERS,
            'domain' => $domain,
            'http_code' => $edge['code'],
            'detail' => "answered {$edge['code']} with a different response than the application itself",
        ];
    }

    /** @return array{verdict: string, domain: ?string, http_code: ?int, detail: string} */
    private static function reachSkipped(?string $domain, string $detail): array
    {
        return ['verdict' => self::REACH_SKIPPED, 'domain' => $domain, 'http_code' => null, 'detail' => $detail];
    }

    /**
     * @param list<array<string, mixed>> $ports
     * @return array<string, mixed>|null
     */
    private static function firstAnsweringPort(array $ports): ?array
    {
        foreach ($ports as $port) {
            if (($port['status'] ?? null) === self::STATUS_OK) {
                return $port;
            }
        }

        return null;
    }

    /**
     * Fetch through the webserver as a visitor would, from the host.
     *
     * No DNS: the Host header carries the name and the address is the one the
     * vhost binds, so the check works for a domain whose DNS has not been
     * pointed here yet -- which is most of them, most of the time.
     */
    public static function edgeProbeScript(string $domain, string $ip, int $timeout = 5): string
    {
        $timeout = max(1, $timeout);
        $domain = escapeshellarg($domain);
        $ip = escapeshellarg($ip);

        return <<<SH
set -u
host={$domain}
addr={$ip}
f=\$(mktemp)
code=\$(curl -sS -m {$timeout} -o "\$f" -w '%{http_code}' -H "Host: \$host" "http://\$addr/" 2>/dev/null) || code=000
marker=no
if [ "\$code" = "404" ] && grep -q 'Page Not Found' "\$f" && grep -q 'error-page' "\$f"; then marker=yes; fi
printf '%s\t%s\t%s' "\${code:-000}" "\$(head -c 2048 "\$f" | sha256sum | cut -d' ' -f1)" "\$marker"
rm -f "\$f"
exit 0
SH;
    }

    /**
     * The same fetch against the application itself, for the comparison --
     * twice, so the caller can tell whether this application's body is stable
     * enough to compare at all.
     *
     * Nextcloud, ownCloud and every framework with CSRF protection stamp a
     * fresh token into each response, so two identical requests a millisecond
     * apart already differ. Hashing one of them and comparing it to the edge
     * declared healthy sites unreachable. Fetching twice answers that without
     * having to guess at token formats: if the application cannot reproduce
     * its own bytes, neither can the edge, and the body is not evidence.
     */
    public static function appProbeScript(string $scheme, int $port, int $timeout = 5, ?string $domain = null): string
    {
        $timeout = max(1, $timeout);
        $scheme = $scheme === 'https' ? 'https' : 'http';
        // Same Host as the edge fetch, so an app that echoes it into the page still matches.
        $host = $domain !== null && $domain !== '' ? '-H ' . escapeshellarg("Host: {$domain}") . ' ' : '';

        return <<<SH
set -u
f=\$(mktemp)
g=\$(mktemp)
code=\$(curl -sS -k -m {$timeout} {$host}-o "\$f" -w '%{http_code}' '{$scheme}://127.0.0.1:{$port}/' 2>/dev/null) || code=000
curl -sS -k -m {$timeout} {$host}-o "\$g" '{$scheme}://127.0.0.1:{$port}/' >/dev/null 2>&1 || :
printf '%s\t%s\t%s\t%s' "\${code:-000}" "\$(head -c 2048 "\$f" | sha256sum | cut -d' ' -f1)" "no" "\$(head -c 2048 "\$g" | sha256sum | cut -d' ' -f1)"
rm -f "\$f" "\$g"
exit 0
SH;
    }

    /**
     * @return array{code: int, hash: string, default404: bool, hash2: string}
     */
    public static function parseFingerprint(string $raw): array
    {
        $parts = explode("\t", trim($raw));

        return [
            'code' => (int) ($parts[0] ?? '0'),
            'hash' => trim($parts[1] ?? ''),
            'default404' => trim($parts[2] ?? 'no') === 'yes',
            // Only the application probe sends this: the hash of a second,
            // identical fetch. Empty from the edge probe, which fetches once.
            'hash2' => trim($parts[3] ?? ''),
        ];
    }

    /**
     * Whether this application reproduces its own bytes between two identical
     * requests. When it does not -- a CSRF token, a nonce, a timestamp -- the
     * body cannot be used as evidence about the edge.
     *
     * Absent means not measured, which reads as reproducible: a probe that
     * did not ask the question must not weaken the comparison.
     *
     * @param array<string, mixed> $app
     */
    private static function bodyIsReproducible(array $app): bool
    {
        $second = (string) ($app['hash2'] ?? '');

        return $second === '' || $second === (string) ($app['hash'] ?? '');
    }

    /**
     * One human-readable line for the reachability verdict.
     *
     * @param array{verdict: string, domain: ?string, http_code: ?int, detail: string} $result
     */
    public static function describeReach(array $result): string
    {
        $url = 'http://' . ($result['domain'] ?? '?') . '/';

        return match ($result['verdict']) {
            self::REACH_OK => "Reachable: {$url} answered {$result['detail']} through the webserver",
            self::REACH_SKIPPED => "Reachability check skipped: {$result['detail']}",
            default => "Not reachable: {$url} {$result['detail']}",
        };
    }

    /**
     * Detail keys the verdict is remembered under, so the pipeline and the
     * telemetry report can both read what the probe found without running it
     * a second time.
     */
    public const DETAIL_CHECKED = 'health_checked';
    public const DETAIL_HEALTHY = 'health_healthy';
    public const DETAIL_PORTS = 'health_ports';

    /** The reachability verdict, one of the REACH_* constants. */
    public const DETAIL_REACHABLE = 'health_reachable';

    /** What the application is serving, one of {@see CheckRunner}'s words. */
    public const DETAIL_SERVING = 'health_serving';

    /** The checks that failed, so the deploy can act on their severities. */
    public const DETAIL_CHECKS = 'health_failed_checks';

    /**
     * What to say when an application publishes ports and answers on none of
     * them. Here rather than in the controller that used to own it, so the
     * rebuild path can say the same thing rather than its own version.
     */
    public const NOT_ANSWERING = 'The application started but did not answer on any port it publishes. '
        . 'The deploy log has the per-port result; a worker or queue-only app can ignore this.';

    /** Frozen by the deploy; the runtime decides which check group applies. */
    public const DETAIL_RUNTIME = 'deploy_runtime';

    /** Frozen by the deploy; the platform decides which checks it added. */
    public const DETAIL_PLATFORM = 'deploy_platform';

    /**
     * The budget the *deploy-time* probe gets, which is not the one an
     * operator asking "is it up right now?" wants.
     *
     * `docker compose up` returning is not an application serving. A stock
     * laravel/laravel spends its container's first seventy seconds running
     * composer, `artisan optimize` and its own entrypoint before `artisan
     * serve` binds a socket -- measured, not guessed. A probe that gives up
     * in twenty seconds calls that install broken, and being wrong in that
     * direction tells a customer their working deploy failed.
     *
     * The cost lands only where we want it: the loop breaks the moment a port
     * answers, so a healthy app pays nothing and the full budget is spent
     * exclusively on an application that is genuinely not answering -- which
     * is precisely the case worth being sure about.
     */
    private const DEPLOY_TIMEOUT = 3;
    private const DEPLOY_ATTEMPTS = 15;
    private const DEPLOY_DELAY = 4;

    /**
     * Run the check, write one line per port into the running deploy log, and
     * remember the verdict on the account.
     *
     * Still never throws and still never fails a deploy. Remembering is not
     * gating: what reads the verdict afterwards decides what it is worth, and
     * an app that answers nothing on HTTP is a legitimate deploy for a queue
     * consumer.
     */
    public function report(): void
    {
        $logger = $this->dind->shell()->logger();
        if ($logger === null) {
            return;
        }

        $report = $this->observe(self::DEPLOY_TIMEOUT, self::DEPLOY_ATTEMPTS, self::DEPLOY_DELAY);
        if ($report === null) {
            return;
        }

        if (isset($report['error'])) {
            $logger->warn('Health check: ' . $report['error']);
            return;
        }

        if ($report['healthy'] === null) {
            $logger->dim('Health check: the application publishes no port to probe');
            return;
        }

        foreach ($report['ports'] as $result) {
            if ($result['status'] === self::STATUS_OK) {
                $logger->ok(self::describe($result));
            } else {
                $logger->warn(self::describe($result));
            }
        }

        $this->reportReachability($report, $logger);
        $this->reportChecks($report, $logger);
    }

    /**
     * The third line: the application answered, and this is whether what it
     * answered with is the application.
     *
     * Warned, never failed. An engine that stopped a deploy over a page it
     * did not recognise would be an engine nobody deploys twice; what this
     * buys is that the customer is told, in the deploy log and on the
     * account, rather than finding out from a visitor.
     *
     * Only failures are logged. A deploy log that recited every question it
     * asked and answered would bury the four lines above it.
     *
     * @param array<string, mixed> $report
     */
    private function reportChecks(array $report, DeployLogger $logger): void
    {
        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (!is_array($check) || ($check['status'] ?? null) !== CheckResult::STATUS_FAIL) {
                continue;
            }

            $severity = (string) ($check['severity'] ?? HealthCheck::SEVERITY_ERROR);
            $line = ($severity === HealthCheck::SEVERITY_ERROR ? 'Check failed: ' : 'Check: ') . $check['title'];
            foreach (['detail', 'fix'] as $key) {
                if (is_string($check[$key] ?? null) && $check[$key] !== '') {
                    $line .= ' ' . $check[$key];
                }
            }
            // `info` is for things worth recording and not worth colouring.
            $severity === HealthCheck::SEVERITY_INFO ? $logger->dim($line) : $logger->warn($line);
        }
    }

    /**
     * The second line: the app started, and this is whether anyone can get to
     * it. Warned rather than failed -- an application that varies by Host is
     * not a broken deploy, and a check nobody can switch off is a check
     * somebody switches off.
     *
     * @param array<string, mixed> $report
     */
    private function reportReachability(array $report, DeployLogger $logger): void
    {
        $reach = $report['domain'] ?? null;
        if (!is_array($reach)) {
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
     * Probe the application and remember what it found.
     *
     * The deploy's own use of this is {@see report()}, which adds the log
     * lines; the periodic sweep has no deploy log to write to and wants
     * exactly this -- ask, record, hand the answer back.
     *
     * Null when the probe could not be carried out at all, which is a
     * different thing from an application that did not answer: the second is
     * a verdict and gets remembered, the first is not and does not.
     *
     * @return array<string, mixed>|null
     */
    public function observe(int $timeout = 5, int $attempts = 3, int $delay = 2): ?array
    {
        try {
            $report = $this->check($timeout, $attempts, $delay);
        } catch (\Throwable $e) {
            Log::warning('App health check failed to run: ' . $e->getMessage());

            return null;
        }

        $this->remember($report);

        return $report;
    }

    /**
     * The checks that failed on this account's last observation, whatever
     * their severity.
     *
     * {@see errorCheckMessages()} answers what should stop a deploy reading
     * as clean; this answers what is worth telling anyone about, which is a
     * wider question and the one the periodic sweep asks.
     *
     * @param array<string, mixed> $details the account's details
     * @return list<array{id: string, severity: string, message: string}>
     */
    public static function failedChecks(array $details): array
    {
        $failures = [];
        foreach ((array) ($details[self::DETAIL_CHECKS] ?? []) as $failure) {
            if (!is_array($failure) || !is_string($failure['id'] ?? null)) {
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
     * Write the verdict onto the account, ports and status codes only.
     *
     * `checked` is what tells "nothing to probe" apart from "nothing
     * answered" later on, when the ports list is empty in both cases and only
     * one of them is a problem.
     *
     * @param array{healthy: bool|null, ports: list<array<string, mixed>>, error?: string} $report
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
            // The verdict is diagnosis. Losing it must not cost the deploy
            // that just succeeded anything at all.
            Log::debug('Could not record the app health verdict: ' . $e->getMessage());
        }
    }

    /**
     * The failing checks in a report, flattened to what is worth remembering.
     *
     * @param array<string, mixed> $report
     * @return list<array{id: string, severity: string, message: string}>
     */
    private static function failuresOf(array $report): array
    {
        $failures = [];
        foreach ((array) ($report['checks'] ?? []) as $check) {
            if (!is_array($check) || ($check['status'] ?? null) !== CheckResult::STATUS_FAIL) {
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
     * Sentences for the checks that failed badly enough to say a deploy did
     * not entirely work.
     *
     * Errors only. That is what the severity is for: a project still showing
     * the welcome page because nothing has been deployed into it is a warning
     * and belongs in the log, not on the account as a partial deploy -- and
     * an engine that marked every empty account partial would teach everyone
     * to ignore the field.
     *
     * @param array<string, mixed> $details the account's details
     * @return list<string>
     */
    public static function errorCheckMessages(array $details): array
    {
        $messages = [];
        foreach ((array) ($details[self::DETAIL_CHECKS] ?? []) as $failure) {
            if (!is_array($failure) || ($failure['severity'] ?? null) !== HealthCheck::SEVERITY_ERROR) {
                continue;
            }
            $message = $failure['message'] ?? '';
            if (is_string($message) && trim($message) !== '') {
                $messages[] = trim($message);
            }
        }

        return $messages;
    }

    /**
     * Everything about this account's serving verdict that should stop a
     * deploy being called clean, in the order it is worth reading.
     *
     * The one place the question is answered, because three callers ask it
     * and each of them writes down a different consequence: the create path
     * finishes its deploy log with these, `User::rebuild()` finishes its own
     * with them, and `recordRebuildSucceeded()` puts them on the account
     * record. They diverged twice already -- rebuild reported a degraded
     * application as a clean success for as long as it finished its log
     * itself, and this line, the one case where nothing answers at all, was
     * only ever asked on the create path.
     *
     * Not answering comes first: a port that never replied explains every
     * check that follows it, and the checks in that state are describing an
     * error page rather than the application.
     *
     * @param array<string, mixed> $details
     * @return list<string>
     */
    public static function servingWarnings(array $details): array
    {
        $warnings = self::nothingAnswered($details) ? [self::NOT_ANSWERING] : [];

        return array_merge($warnings, self::errorCheckMessages($details));
    }

    /**
     * Did every port that was probed fail?
     *
     * The strict reading on purpose. One failing port out of three is a
     * partially wrong recipe worth a log line; *nothing* answering is an
     * application that is not serving, and that is the only case confident
     * enough to change what a deploy reports.
     *
     * @param array<string, mixed> $details
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
        if (!is_array($ports) || $ports === []) {
            return false;
        }

        foreach ($ports as $port) {
            if (is_array($port) && ($port['status'] ?? null) === self::STATUS_OK) {
                return false;
            }
        }

        return true;
    }

    /**
     * One human-readable line for a single port's result.
     *
     * @param array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string} $result
     */
    public static function describe(array $result): string
    {
        $scheme = $result['scheme'] ?? 'http';
        $target = "{$scheme}://127.0.0.1:{$result['port']}/";

        if ($result['status'] === self::STATUS_OK) {
            return "Health check: {$target} answered {$result['detail']} ({$result['time']}s)";
        }

        // A 5xx did answer — saying it "did not answer" would send whoever
        // reads the log looking for a dead port instead of a broken app.
        if ($result['http_code'] !== null) {
            return "Health check: {$target} answered {$result['detail']} — the application is failing";
        }

        return "Health check: {$target} did not answer — {$result['detail']}";
    }

    /**
     * The probe, as a shell script run inside the account container.
     *
     * One script rather than one exec per port: each exec is a
     * `docker compose exec` round trip, and an app with three published ports
     * would pay for three of them.
     *
     * HTTPS is tried only when HTTP got nothing back, so an app terminating
     * TLS itself is not reported down. `-k` throughout — a self-signed
     * certificate on 127.0.0.1 is not what this check is about.
     *
     * @param list<int> $ports
     */
    public static function probeScript(array $ports, int $timeout = 5, int $attempts = 3, int $delay = 2): string
    {
        $timeout = max(1, $timeout);
        $attempts = max(1, $attempts);
        $delay = max(0, $delay);
        $sample = ProbedResponse::SAMPLE_BYTES;
        $list = implode(' ', array_map('intval', $ports));

        return <<<SH
set -u

# Echoes "CODE TIME<tab>ERROR<tab>BODY". CODE is 000 when nothing answered.
# BODY is the first {$sample} bytes, base64 so it survives a line-oriented
# protocol: a page containing a tab or a newline would otherwise be read as
# three more ports. It is what tells the engine's own placeholder apart from
# an application, which no status code can.
# Follows a relative redirect, because the landing page is what a visitor
# gets and is where the failure usually is: Firefly III answers / with a 302
# to /login and /login with a 500, and probing only / reported twelve passing
# checks on a site that served an error page to everyone.
#
# Relative only, and bounded. An application that canonicalises to its own
# absolute URL -- WordPress on every site -- must not send this probe out of
# the container and onto the public internet; that case is already understood
# one layer up, where a local redirect against a remote 200 reads as healthy.
probe() {
    err_file=\$(mktemp)
    body_file=\$(mktemp)
    head_file=\$(mktemp)
    path=/
    hops=0
    while :; do
        out=\$(curl -sS -k -o "\$body_file" -D "\$head_file" -w '%{http_code} %{time_total}' --max-time {$timeout} "\$1://127.0.0.1:\$2\$path" 2>"\$err_file") || true
        code=\${out%% *}
        case "\$code" in
            30[12378])
                location=\$(sed -n 's/^[Ll][Oo][Cc][Aa][Tt][Ii][Oo][Nn]:[[:space:]]*//p' "\$head_file" | tr -d '\r\n' | head -n 1)
                case "\$location" in
                    /*)
                        hops=\$((hops + 1))
                        if [ "\$hops" -le 5 ]; then
                            path=\$location
                            continue
                        fi
                        ;;
                esac
                ;;
        esac
        break
    done
    printf '%s\\t%s\\t%s' "\${out:-000 0}" "\$(tr -d '\\r\\n' <"\$err_file" | sed -e 's/^curl: ([0-9]*) //' -e 's/ after [0-9]* ms:.*//' | cut -c1-120)" "\$(head -c {$sample} "\$body_file" | base64 | tr -d '\\r\\n')"
    rm -f "\$err_file" "\$body_file" "\$head_file"
}

for port in {$list}; do
    attempt=1
    while :; do
        scheme=http
        result=\$(probe http "\$port")
        if [ "\${result%% *}" = "000" ]; then
            secure=\$(probe https "\$port")
            if [ "\${secure%% *}" != "000" ]; then
                scheme=https
                result=\$secure
            fi
        fi
        [ "\${result%% *}" != "000" ] && break
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
     * Ceiling for the whole probe: every port may burn both schemes on every
     * attempt, plus the sleeps between them.
     *
     * @param list<int> $ports
     */
    public static function timeBudget(array $ports, int $timeout = 5, int $attempts = 3, int $delay = 2): int
    {
        $perPort = ($attempts * (2 * max(1, $timeout) + max(0, $delay)));

        return 30 + count($ports) * $perPort;
    }

    /**
     * @param list<int> $ports
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
                isset($fields[4]) ? trim($fields[4]) : ''
            );
        }

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
     * @param list<array{status: string}> $results
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
     * @return array{port: int, scheme: ?string, status: string, http_code: ?int, time: ?float, detail: string}
     */
    private static function result(
        int $port,
        string $scheme,
        int $code,
        float $time,
        string $error,
        string $bodyBase64 = ''
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
            // Not reported to the caller: it is evidence for the checks, and
            // a page of somebody's application does not belong in an API
            // response about whether that application is up.
            'body' => self::decodeSample($bodyBase64),
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
     * First line of an exception message, capped. A wedged account container
     * returns a multi-line OCI runtime error; the whole thing in a table cell
     * or a deploy log line hides everything around it.
     */
    public static function trimReason(string $message): string
    {
        $first = trim((string) (preg_split('/\r?\n/', trim($message))[0] ?? ''));
        if ($first === '') {
            return 'unknown error';
        }

        return mb_strlen($first) > 160 ? mb_substr($first, 0, 157) . '...' : $first;
    }
}
