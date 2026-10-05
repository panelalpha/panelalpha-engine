<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Platform\Strategies;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\AppHealth;
use Illuminate\Support\Facades\Log;

/**
 * A redeploy with the site always served (engine#33): the new version starts
 * beside the running one, traffic moves to it once it answers, the app's own
 * services are replaced behind it, and traffic moves back before the second
 * copy goes. The rest of the engine only ever sees the one project it had.
 */
final class ZeroDowntimeRedeploy
{
    /** On {@see \App\System\Project\Dind\AppLauncher::start()}'s result: the running version was left serving. */
    public const PREVIOUS_KEPT = 'previous_kept';

    /** {@see begin()}: traffic is on the new generation. */
    public const SWITCHED = 'switched';

    /** {@see begin()}: it could not be started beside the running one; replace in place. */
    public const IN_PLACE = 'in_place';

    /** Root-owned tmpfs in the account container: nothing the account can plant a link in. */
    private const OVERRIDE_FILE = '/run/panelalpha-next-generation.yml';

    /** Time for the webserver's old workers to finish what they were sending to the old port. */
    private const DRAIN_SECONDS = 5;

    private const START_PERIOD_SECONDS = 300;

    /** How long an app answering 5xx gets to stop doing so: a backend may still be booting. */
    private const SERVER_ERROR_SECONDS = 60;

    private const POLL_SECONDS = 2;

    private const PROBE_TIMEOUT_SECONDS = 3;

    private const COMPOSE_TIMEOUT_SECONDS = 600;

    private const QUICK_TIMEOUT_SECONDS = 30;

    private const LOG_LINES = 40;

    /** @var array<int, int> published port => the second generation's port */
    private array $routes = [];

    /** @var array<int, int> the port the site is on => the second generation's copy of the routed port */
    private array $switched = [];

    /** @var list<int> */
    private array $movedRules = [];

    private function __construct(
        private readonly DindProject $project,
        private readonly NextGeneration $next,
        private readonly DeployLogger $logger,
        private readonly int $routedPort,
        private readonly int $servedPort,
    ) {
    }

    /** Null to replace the app in place; the deploy log says why when something ran. */
    public static function plan(DindProject $project): ?self
    {
        $logger = $project->shell()->logger();
        if ($logger === null) {
            return null;
        }
        // This deploy holds the lock: a second generation still here is an
        // interrupted deploy's.
        GenerationSweep::settleNext($project);

        // Nothing running, nothing to keep: a first start says nothing.
        $user = $project->userModel();
        $routed = $user->getAppPort();
        if ($routed === null || self::canonicalId($project, null) === null) {
            return null;
        }
        $config = self::composeConfig($project);
        $strategy = $user->getDeployStrategy();
        $next = $config === null
            ? 'its compose file could not be read'
            : NextGeneration::plan($config, $routed, in_array($strategy, [Strategies::COMPOSE, Strategies::PAEMD], true));
        if ($next instanceof NextGeneration && self::canonicalId($project, $next->service) === null) {
            $next = "{$next->service} is not running, so there is nothing to keep serving";
        }

        // The rules stay where the running version answers until the switch.
        $served = RoutingSnapshot::servedPort($project->username()) ?? $routed;
        $reason = is_string($next) ? $next : self::whyNotSwitchable($project, $next, $served);
        if ($reason !== null) {
            $logger->info("Replacing the running app in place, not beside it: {$reason}");

            return null;
        }

        /** @var NextGeneration $next */
        $logger->info("Zero-downtime redeploy: the new {$next->service} starts beside the running one, and traffic moves to it once it answers");

        return new self($project, $next, $logger, $routed, $served);
    }

    /**
     * {@see SWITCHED}, {@see IN_PLACE}, or the failed start with {@see PREVIOUS_KEPT}.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    public function begin(): string|array
    {
        $state = new GenerationState($this->project->username());
        $nextProject = $this->next->projectName();
        $state->put(GenerationState::NEXT, ['project' => $nextProject, 'routed' => $this->routedPort, 'routes' => [], 'rules' => []]);

        try {
            $this->project->shell()->execQuiet(
                ['sh', '-c', 'printf %s "$1" | base64 -d > "$2"', 'override', base64_encode($this->next->override()), self::OVERRIDE_FILE],
                [],
                self::QUICK_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            return $this->inPlace('its override could not be written: ' . AppHealth::trimReason($e->getMessage()));
        }

        $this->logger->info("Starting the new version beside the running one (docker compose -p {$nextProject} up -d {$this->next->service})");
        $shell = $this->project->shell();
        $process = $shell->streamProcess(
            $shell->wrap($this->project->userAppComposeCommand([
                '-f', self::OVERRIDE_FILE, '--project-name', $nextProject,
                'up', '-d', '--no-deps', '--no-build', '--pull', 'never', $this->next->service,
            ])),
            [],
            self::COMPOSE_TIMEOUT_SECONDS,
            $this->logger
        );
        $this->logger->throwIfCancelled();
        if ($process->getExitCode() !== 0) {
            return $this->inPlace('the second copy did not start: ' . AppHealth::trimReason($process->getErrorOutput() ?: $process->getOutput()));
        }

        $container = $this->containerOf($nextProject);
        $this->routes = $container === null ? [] : $this->publishedPorts($container);
        if (!isset($this->routes[$this->routedPort])) {
            return $this->inPlace('the second copy published no port Docker would name');
        }
        $state->put(GenerationState::NEXT, ['project' => $nextProject, 'routed' => $this->routedPort, 'routes' => $this->routes, 'rules' => []]);

        $port = $this->routes[$this->routedPort];
        $failure = $this->awaitAnswer($port, $container);
        if ($failure !== null) {
            $this->logger->warn("The new version did not become healthy on port {$port}: {$failure}");
            $this->recordOutput($container);
            $this->discard();

            return [
                'stdout' => '',
                'stderr' => "The new version did not become healthy: {$failure}. The previous version is still serving.",
                'exit_code' => 1,
                self::PREVIOUS_KEPT => true,
            ];
        }
        $this->logger->ok("The new version answers on port {$port}");
        RoutingSnapshot::markGated($this->project->username());

        $this->switched = self::switchMap($this->routes, $this->routedPort, $this->servedPort);
        $switch = new RouteSwitch($this->project->system(), $this->project->username());
        try {
            $this->movedRules = $switch->move($this->switched);
        } catch (\Throwable $e) {
            // The rules are saved before the reload that failed: put them back.
            try {
                $switch->move(self::inverse($this->switched));
            } catch (\Throwable) {
            }

            return $this->inPlace('the webserver could not be switched: ' . AppHealth::trimReason($e->getMessage()));
        }
        if ($this->movedRules === []) {
            return $this->inPlace("no proxy rule sends traffic to port {$this->servedPort}");
        }
        $state->put(GenerationState::NEXT, ['project' => $nextProject, 'routed' => $this->routedPort, 'routes' => $this->switched, 'rules' => $this->movedRules]);
        $this->logger->info("Traffic moved to the new version on port {$port}; the running one finishes its requests");
        sleep(self::DRAIN_SECONDS);

        return self::SWITCHED;
    }

    /** @return list<string> */
    public function generationServices(): array
    {
        return $this->next->generationServices($this->project->userAppDirPath());
    }

    /** Once the replaced app answers on its own port, traffic goes there: the new version's port. */
    public function finish(bool $replaced): void
    {
        if (!$replaced) {
            $this->abandon();

            return;
        }
        if ($this->movedRules !== []) {
            $container = $this->canonicalContainer();
            $failure = $this->awaitAnswer($this->routedPort, $container);
            if ($failure !== null) {
                $this->logger->warn("The replaced app does not answer on port {$this->routedPort} yet: {$failure}");
            }
        }
        try {
            $this->restoreRoutes(self::inverse($this->routes));
        } catch (\Throwable $e) {
            $this->logger->warn('Traffic could not be moved back yet, so the second copy keeps serving: ' . AppHealth::trimReason($e->getMessage()));

            return;
        }
        $this->discard();
        $this->logger->info("Traffic is back on the app's own port {$this->routedPort}; the second copy was removed");
    }

    /** Safe to call twice. A failed switch back leaves the second copy serving for the sweep. */
    public function abandon(): void
    {
        try {
            $this->restoreRoutes(self::inverse($this->switched));
        } catch (\Throwable $e) {
            $this->logger->warn('Traffic could not be moved back yet, so the second copy keeps serving: ' . AppHealth::trimReason($e->getMessage()));

            return;
        }
        $this->discard();
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    public static function previousKept(string $stdout, string $stderr, int $exitCode): array
    {
        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit_code' => $exitCode === 0 ? 1 : $exitCode, self::PREVIOUS_KEPT => true];
    }

    /**
     * Services whose image has to come from a registry: not built here, not
     * an image another service builds, and allowed to be pulled.
     *
     * @param array<string, mixed> $config `docker compose config --format json`
     * @return list<string>
     */
    public static function servicesToPull(array $config): array
    {
        $project = (string) ($config['name'] ?? '');
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        $built = [];
        foreach ($services as $name => $service) {
            if (is_array($service) && isset($service['build'])) {
                $built[] = self::withoutLatest((string) ($service['image'] ?? "{$project}-{$name}"));
            }
        }
        $pull = [];
        foreach ($services as $name => $service) {
            $image = is_array($service) ? trim((string) ($service['image'] ?? '')) : '';
            $policy = strtolower((string) ($service['pull_policy'] ?? ''));
            if ($image === '' || isset($service['build']) || in_array($policy, ['never', 'build'], true)
                || in_array(self::withoutLatest($image), $built, true)) {
                continue;
            }
            $pull[] = (string) $name;
        }

        return $pull;
    }

    private static function withoutLatest(string $image): string
    {
        return str_ends_with($image, ':latest') ? substr($image, 0, -strlen(':latest')) : $image;
    }

    /** Remove a second generation's containers and its override. */
    public static function discardProject(DindProject $project, string $nextProject): void
    {
        $script = 'ids=$(docker ps -aq --filter "label=com.docker.compose.project=$1"); '
            . '[ -z "$ids" ] || docker rm -f -v $ids >/dev/null; rm -f "$2"';
        $project->shell()->execQuiet(['bash', '-c', $script, 'discard', $nextProject, self::OVERRIDE_FILE], [], 120);
    }

    /**
     * What the switch moves: the port the site is on now to the copy of the
     * routed port. Rules to the app's other ports are not the site's route.
     *
     * @param array<int, int> $routes the new version's published port => the second copy's
     * @return array<int, int>
     */
    public static function switchMap(array $routes, int $routed, int $served): array
    {
        return isset($routes[$routed]) ? [$served => $routes[$routed]] : [];
    }

    /**
     * @param array<int, int> $routes
     * @return array<int, int>
     */
    public static function inverse(array $routes): array
    {
        $inverse = [];
        foreach ($routes as $from => $to) {
            $inverse[(int) $to] = (int) $from;
        }

        return $inverse;
    }

    /** From `docker port <container> <target>/tcp`: the IPv4 binding, else any. */
    public static function boundPort(string $dockerPort): ?int
    {
        $fallback = null;
        foreach (preg_split('/\R/', trim($dockerPort)) ?: [] as $line) {
            if (preg_match('/:(\d+)\s*$/', trim($line), $m) !== 1) {
                continue;
            }
            if (str_starts_with(trim($line), '0.0.0.0:')) {
                return (int) $m[1];
            }
            $fallback ??= (int) $m[1];
        }

        return $fallback;
    }

    /** Why "<status> <exit code> <restarts>" from `docker inspect` will not answer; null while it may. */
    public static function containerFailure(string $inspect): ?string
    {
        $fields = preg_split('/\s+/', trim($inspect)) ?: [];
        $status = strtolower($fields[0] ?? '');
        $exit = $fields[1] ?? '?';
        $restarts = (int) ($fields[2] ?? 0);
        if ($status === '') {
            return 'its container is gone';
        }
        if (in_array($status, ['exited', 'dead'], true)) {
            return "it exited with code {$exit}";
        }
        if ($status === 'restarting') {
            return "it keeps restarting (last exit code {$exit})";
        }
        // Running again after a restart: the exit code has been reset by then.
        if ($restarts > 0) {
            return "it keeps restarting ({$restarts} restarts so far)";
        }

        return null;
    }

    /** A running container of the app's own project (of $service), or null. */
    public static function canonicalId(DindProject $project, ?string $service): ?string
    {
        $rest = ['ps', '--quiet', '--status', 'running'];
        if ($service !== null) {
            $rest[] = $service;
        }
        try {
            $out = trim($project->shell()->execAsUserQuiet($project->userAppComposeCommand($rest), [], self::QUICK_TIMEOUT_SECONDS));
        } catch (\Throwable) {
            return null;
        }
        $first = trim((string) (preg_split('/\R/', $out)[0] ?? ''));

        return $first === '' ? null : $first;
    }

    /** @return ?array<string, mixed> */
    public static function composeConfig(DindProject $project): ?array
    {
        try {
            $raw = $project->shell()->execAsUserQuiet(
                $project->userAppComposeCommand(['config', '--format', 'json']),
                [],
                self::QUICK_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return null;
        }
        $config = json_decode($raw, true);

        return is_array($config) ? $config : null;
    }

    private static function whyNotSwitchable(DindProject $project, NextGeneration $next, int $served): ?string
    {
        $webserver = $project->system()->webserver()->getCurrentWebserver();
        if ($webserver !== RouteSwitch::WEBSERVER) {
            return "the {$webserver} webserver is not one the engine switches between ports";
        }
        $switch = new RouteSwitch($project->system(), $project->username());
        $ports = array_values(array_unique([...array_keys($next->ports), $served]));
        $tunnelled = RouteSwitch::tunnelledDomain($switch->httpRulesTo($ports));
        if ($tunnelled !== null) {
            return "{$tunnelled} reaches it through a Cloudflare tunnel, which a vhost reload does not move";
        }

        $answer = self::probe($project, $served, 2);
        if ($answer['status'] !== AppHealth::STATUS_OK) {
            return "the running version does not answer on port {$served} ({$answer['detail']}), so there is nothing to keep serving";
        }

        return null;
    }

    /** Null once $port answers below 500 within $seconds, else what it said last. */
    public static function answersWithin(DindProject $project, int $port, int $seconds): ?string
    {
        $deadline = time() + $seconds;
        while (true) {
            $answer = self::probe($project, $port);
            if ($answer['status'] === AppHealth::STATUS_OK) {
                return null;
            }
            if (time() >= $deadline) {
                return $answer['http_code'] !== null ? "it answers HTTP {$answer['http_code']}" : $answer['detail'];
            }
            sleep(self::POLL_SECONDS);
        }
    }

    /**
     * @return array{status: string, http_code: ?int, detail: string}
     */
    private static function probe(DindProject $project, int $port, int $attempts = 1): array
    {
        $user = $project->userModel();
        $domain = $user->getMainDomain()?->domain;
        $https = $user->getAppPortScheme() === 'https' ? [$port] : [];
        try {
            $raw = $project->shell()->execQuiet(
                ['bash', '-c', AppHealth::probeScript([$port], self::PROBE_TIMEOUT_SECONDS, $attempts, 1, is_string($domain) ? $domain : null, true, $https)],
                [],
                AppHealth::timeBudget([$port], self::PROBE_TIMEOUT_SECONDS, $attempts, 1)
            );
        } catch (\Throwable $e) {
            return ['status' => AppHealth::STATUS_FAIL, 'http_code' => null, 'detail' => 'the probe could not run: ' . AppHealth::trimReason($e->getMessage())];
        }
        $result = AppHealth::parseProbeOutput($raw, [$port])[0];

        return ['status' => $result['status'], 'http_code' => $result['http_code'], 'detail' => $result['detail']];
    }

    /** Null once $port answers below 500, else why it will not. */
    private function awaitAnswer(int $port, ?string $container): ?string
    {
        $deadline = time() + $this->startPeriod();
        $erroringSince = null;
        while (true) {
            $this->logger->throwIfCancelled();
            $answer = self::probe($this->project, $port);
            if ($answer['status'] === AppHealth::STATUS_OK) {
                return null;
            }
            $dead = $container === null ? 'its container is gone' : self::containerFailure($this->inspect($container));
            if ($dead !== null) {
                return $dead;
            }
            if ($answer['http_code'] !== null) {
                $erroringSince ??= time();
                if (time() - $erroringSince >= self::SERVER_ERROR_SECONDS) {
                    return "it answers HTTP {$answer['http_code']}";
                }
            }
            if (time() >= $deadline) {
                return 'nothing answered within ' . $this->startPeriod() . " s ({$answer['detail']})";
            }
            sleep(self::POLL_SECONDS);
        }
    }

    private function startPeriod(): int
    {
        $declared = $this->project->userModel()->getDetails()[AppHealth::DETAIL_START_PERIOD] ?? null;

        return is_int($declared) ? max(30, $declared) : self::START_PERIOD_SECONDS;
    }

    private function inspect(string $container): string
    {
        try {
            return $this->project->shell()->execQuiet(
                ['docker', 'inspect', '--format', '{{.State.Status}} {{.State.ExitCode}} {{.RestartCount}}', $container],
                [],
                self::QUICK_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return '';
        }
    }

    private function containerOf(string $composeProject): ?string
    {
        try {
            $out = $this->project->shell()->execQuiet([
                'docker', 'ps', '-aq',
                '--filter', 'label=com.docker.compose.project=' . $composeProject,
                '--filter', 'label=com.docker.compose.service=' . $this->next->service,
            ], [], self::QUICK_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return null;
        }
        $first = trim((string) (preg_split('/\R/', trim($out))[0] ?? ''));

        return $first === '' ? null : $first;
    }

    private function canonicalContainer(): ?string
    {
        return self::canonicalId($this->project, $this->next->service);
    }

    /** @return array<int, int> */
    private function publishedPorts(string $container): array
    {
        $routes = [];
        foreach ($this->next->ports as $published => $target) {
            try {
                $out = $this->project->shell()->execQuiet(['docker', 'port', $container, $target . '/tcp'], [], self::QUICK_TIMEOUT_SECONDS);
            } catch (\Throwable) {
                continue;
            }
            $bound = self::boundPort($out);
            if ($bound !== null) {
                $routes[$published] = $bound;
            }
        }

        return $routes;
    }

    private function recordOutput(?string $container): void
    {
        if ($container === null) {
            return;
        }
        try {
            $out = trim($this->project->shell()->execQuiet(
                ['sh', '-c', 'docker logs --tail ' . self::LOG_LINES . ' "$1" 2>&1', 'logs', $container],
                [],
                self::QUICK_TIMEOUT_SECONDS
            ));
        } catch (\Throwable) {
            return;
        }
        if ($out === '') {
            return;
        }
        $this->logger->info('The new version printed:');
        foreach (preg_split('/\R/', $out) ?: [] as $line) {
            if (trim($line) !== '') {
                $this->logger->info($line);
            }
        }
    }

    /** @param array<int, int> $map */
    private function restoreRoutes(array $map): void
    {
        if ($this->movedRules === []) {
            return;
        }
        (new RouteSwitch($this->project->system(), $this->project->username()))->move($map, $this->movedRules);
        $this->movedRules = [];
        sleep(self::DRAIN_SECONDS);
    }

    /** Left recorded when it fails, for the sweep to retry. */
    private function discard(): void
    {
        try {
            self::discardProject($this->project, $this->next->projectName());
        } catch (\Throwable $e) {
            Log::warning("Could not remove the second generation of {$this->project->username()}: " . $e->getMessage());

            return;
        }
        (new GenerationState($this->project->username()))->forget(GenerationState::NEXT);
    }

    private function inPlace(string $reason): string
    {
        $this->logger->warn("Replacing the running app in place after all: {$reason}");
        $this->discard();

        return self::IN_PLACE;
    }
}
