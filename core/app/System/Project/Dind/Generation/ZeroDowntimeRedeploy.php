<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\DeployLog\DeployFailureExplainer;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\ProxyRule;
use App\Models\User;
use App\System\Project\Dind\AnsweringPort;
use App\System\Project\Dind\AppHealth;
use App\System\Project\Dind\AppLauncher;
use App\System\Project\Dind\AppPortAlignment;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * A redeploy with the site always served: the new version starts
 * beside the running one, traffic moves to it once it answers, the app's own
 * services are replaced behind it, and traffic moves back before the second
 * copy goes. The rest of the engine only ever sees the one project it had.
 */
final class ZeroDowntimeRedeploy
{
    /** On {@see AppLauncher::start()}'s result: the running version was left serving. */
    public const PREVIOUS_KEPT = 'previous_kept';

    /** On a failed start's result: the new version's second copy was left serving, nothing else answering. */
    public const COPY_KEPT = 'copy_kept';

    /** On a failed start's result: the deploy log's line for what serves, when it is not the running version as it was. */
    public const SERVING = 'serving';

    /** {@see begin()}: traffic is on the new generation. */
    public const SWITCHED = 'switched';

    /**
     * {@see begin()}: replace in place, for reasons that say nothing about the new version: the override could
     * not be written, compose could not start the copy for a reason other than its command (a network, volume or
     * image missing, an address the running copy holds), the realigned run file could not be planned, Docker
     * names no port of a copy that runs, or the site's rules could not be moved.
     */
    public const IN_PLACE = 'in_place';

    /** Root-owned tmpfs in the account container: nothing the account can plant a link in. */
    private const OVERRIDE_FILE = '/run/panelalpha-next-generation.yml';

    /** Time for the webserver's old workers to finish what they were sending to the old port. */
    private const DRAIN_SECONDS = 5;

    /** How long a port traffic would go back to gets to answer before the copy is kept instead. */
    private const BACK_SECONDS = 10;

    private const START_PERIOD_SECONDS = 300;

    /** How long an app answering 5xx or an empty page gets to stop doing so: a backend may still be booting. */
    private const SERVER_ERROR_SECONDS = 60;

    private const POLL_SECONDS = 2;

    /** Ends the probe detail of a 2xx with nothing in it. */
    private const EMPTY_PAGE = 'with an empty page';

    private const PROBE_TIMEOUT_SECONDS = 3;

    private const COMPOSE_TIMEOUT_SECONDS = 600;

    private const QUICK_TIMEOUT_SECONDS = 30;

    private const LOG_LINES = 40;

    /**
     * Docker's exit codes for a start that failed on the image's command (not executable, not found).
     * Any other failed start is 128: the daemon or the network, e.g. an address the running copy holds.
     */
    private const COMMAND_FAILED_EXIT_CODES = ['126', '127'];

    /** How long, once one of the copy's guessed ports answered, the others get to stop being silent or answering 5xx. */
    private const CHOICE_SECONDS = 60;

    /** @var array<int, int> published port => the second generation's port */
    private array $routes = [];

    /** @var array<int, int> the port the site is on => the second generation's copy of the routed port */
    private array $switched = [];

    /** @var list<int> */
    private array $movedRules = [];

    /** @var ?array{primary: int, alternatives: list<int>} the guess made again on the copy; null once the last deploy's port was kept */
    private ?array $choice = null;

    private function __construct(
        private readonly DindProject $project,
        private NextGeneration $next,
        private readonly DeployLogger $logger,
        private int $routedPort,
        private readonly int $servedPort,
    ) {}

    /**
     * Null to replace the app in place; the deploy log says why when something ran. This deploy holds the
     * lock, so a second generation still here is an interrupted deploy's. A first start says nothing.
     */
    public static function plan(DindProject $project): ?self
    {
        $logger = $project->shell()->logger();
        if ($logger === null) {
            return null;
        }
        GenerationSweep::settleNext($project);
        if (self::copyHeld($project->username())) {
            return self::notBeside($logger, 'a second copy an earlier redeploy left still serves the site, and it does until the new version answers');
        }

        $user = $project->userModel();
        $routed = $user->getAppPort();
        if ($routed === null || self::canonicalId($project, null) === null) {
            return null;
        }
        $config = self::composeConfig($project);
        $choice = AnsweringPort::choice($project);
        $kept = self::keepLastDeploysPort($project, $user, $logger, $choice, $config, $routed);
        $routed = $kept ?? $routed;
        $next = self::nextOfRunning($project, $config, $routed);
        $served = RoutingSnapshot::servedPort($project->username()) ?? $routed;
        if (is_string($next)) {
            return self::notBeside($logger, $next);
        }
        $reason = self::whyNotSwitchable($project, $next, $served);
        if ($reason !== null) {
            return self::notBeside($logger, $reason);
        }

        $logger->info("Zero-downtime redeploy: the new {$next->service} starts beside the running one, and traffic moves to it once it answers");

        $swap = new self($project, $next, $logger, $routed, $served);
        $swap->choice = $kept === null && $choice !== null && $choice['primary'] === $routed ? $choice : null;

        return $swap;
    }

    private static function notBeside(DeployLogger $logger, string $reason): null
    {
        $logger->info("Replacing the running app in place, not beside it: {$reason}");

        return null;
    }

    /**
     * The same guess, with the same services behind it: the port the last deploy found serving stands,
     * and the site is routed there. That port, or null when none is claimed.
     *
     * @param  ?array{primary: int, alternatives: list<int>}  $choice
     * @param  ?array<string, mixed>  $config
     */
    private static function keepLastDeploysPort(DindProject $project, User $user, DeployLogger $logger, ?array $choice, ?array $config, int $routed): ?int
    {
        $kept = AnsweringPort::claim($user, $choice, $config);
        if ($kept !== null && $kept !== $routed) {
            $project->networking()->routeTo($user, $kept);
            $logger->info("Keeping the site on port {$kept}, where the last deploy found it served, not on the guessed {$routed}: nothing behind those ports has changed");
        }

        return $kept;
    }

    /**
     * The second generation of a service that runs, else why there is none.
     *
     * @param  ?array<string, mixed>  $config
     */
    private static function nextOfRunning(DindProject $project, ?array $config, int $routed): NextGeneration|string
    {
        $next = self::planNext($project, $config, $routed);
        if ($next instanceof NextGeneration && self::canonicalId($project, $next->service) === null) {
            return "{$next->service} is not running, so there is nothing to keep serving";
        }

        return $next;
    }

    /** @param ?array<string, mixed> $config */
    private static function planNext(DindProject $project, ?array $config, int $routed): NextGeneration|string
    {
        return $config === null
            ? 'its compose file could not be read'
            : NextGeneration::plan($config, $routed, self::repositoryCompose($project));
    }

    /**
     * {@see SWITCHED}, {@see IN_PLACE}, or the failed start with {@see PREVIOUS_KEPT}.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    public function begin(?AppPortAlignment $alignment = null): string|array
    {
        $state = new GenerationState($this->project->username());
        $nextProject = $this->next->projectName();
        $notStarted = $this->startBeside($state, $nextProject);
        if ($notStarted !== null) {
            return $notStarted;
        }

        $container = $this->containerOf($nextProject);
        $this->routes = $this->routesOf($container);
        if ($this->realignedToBoundPort($container, $alignment)) {
            $restarted = $this->restartRealigned();
            if ($restarted !== null) {
                return $restarted;
            }
            $container = $this->containerOf($nextProject);
            $this->routes = $this->routesOf($container);
        }
        if (! isset($this->routes[$this->routedPort])) {
            return $this->noRoutedPort($container);
        }
        $failure = $this->chooseOnCopy($container);
        $state->put(GenerationState::NEXT, ['project' => $nextProject, 'routed' => $this->routedPort, 'routes' => $this->routes, 'rules' => []]);

        $port = $this->routes[$this->routedPort];
        $failure ??= $this->awaitAnswer($port, $container);
        if ($failure !== null) {
            return $this->turnedAway($port, $failure, $container);
        }
        $this->logger->ok("The new version answers on port {$port}");
        RoutingSnapshot::markGated($this->project->username());

        return $this->switchTraffic($state, $nextProject, $port);
    }

    /**
     * The second copy started and recorded: null once it runs, else what {@see begin()} returns.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}|null
     */
    private function startBeside(GenerationState $state, string $nextProject): string|array|null
    {
        $state->put(GenerationState::NEXT, ['project' => $nextProject, 'routed' => $this->routedPort, 'routes' => [], 'rules' => []]);
        $failure = $this->writeOverride();
        if ($failure !== null) {
            return $this->inPlace("its override could not be written: {$failure}");
        }

        $this->logger->info("Starting the new version beside the running one (docker compose -p {$nextProject} up -d {$this->next->service})");
        $failure = $this->startCopy(false);

        return $failure === null ? null : $this->notStarted('the second copy did not start', $failure);
    }

    /**
     * Read while it runs: Docker names no port of a copy in restart back-off.
     *
     * @return array<int, int>
     */
    private function routesOf(?string $container): array
    {
        return $container === null ? [] : $this->publishedPorts($container);
    }

    /**
     * A first start forwards to the port the app really binds, so the copy has to, or it is probed on a
     * port nothing listens on. True when the run file was realigned and the copy must start again.
     */
    private function realignedToBoundPort(?string $container, ?AppPortAlignment $alignment): bool
    {
        return $container !== null && $this->next->service === GeneratedCompose::APP_SERVICE
            && ($alignment ?? new AppPortAlignment($this->project))->alignTo($container, fn (): bool => $this->failureOf($container) !== null);
    }

    /**
     * No port of the copy is the routed one. A copy that stopped running is the new version failing,
     * not a reason to replace the running one.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    private function noRoutedPort(?string $container): string|array
    {
        $dead = $container === null ? null : $this->failureOf($container);
        if ($dead !== null) {
            return $this->refuse($dead, $container);
        }

        return $this->inPlace('the second copy published no port Docker would name');
    }

    /** @return array{stdout: string, stderr: string, exit_code: int, previous_kept: true} */
    private function turnedAway(int $port, string $failure, ?string $container): array
    {
        $this->logger->warn("The new version did not become healthy on port {$port}: {$failure}");
        $this->recordOutput($container);
        $this->discard();

        return [
            'stdout' => '',
            'stderr' => self::refusal($failure),
            'exit_code' => 1,
            self::PREVIOUS_KEPT => true,
        ];
    }

    /**
     * The site's rules moved to the copy: {@see SWITCHED} once they are, else what {@see begin()} returns.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, copy_kept: true, serving: string}
     */
    private function switchTraffic(GenerationState $state, string $nextProject, int $port): string|array
    {
        $this->switched = self::switchMap($this->routes, $this->routedPort, $this->servedPort);
        $switch = new RouteSwitch($this->project->system(), $this->project->username());
        try {
            $this->movedRules = $switch->move($this->switched);
        } catch (\Throwable $e) {
            return $this->switchFailed($switch, $state, $nextProject, $e);
        }
        if ($this->movedRules === []) {
            return $this->inPlace("no proxy rule sends traffic to port {$this->servedPort}");
        }
        $state->put(GenerationState::NEXT, ['project' => $nextProject, 'routed' => $this->routedPort, 'routes' => $this->switched, 'rules' => $this->movedRules]);
        $this->logger->info("Traffic moved to the new version on port {$port}; the running one finishes its requests");
        sleep(self::DRAIN_SECONDS);

        return self::SWITCHED;
    }

    /**
     * The rules are moved back; replace in place once they are.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, copy_kept: true, serving: string}
     */
    private function switchFailed(RouteSwitch $switch, GenerationState $state, string $nextProject, \Throwable $e): string|array
    {
        $leftOnCopy = $this->rulesOnCopy($switch);
        try {
            $switch->move(self::inverse($this->switched));
        } catch (\Throwable $undo) {
            return $this->keepCopyAfterFailedSwitch($state, $nextProject, $leftOnCopy, $e, $undo);
        }

        return $this->inPlace('the webserver could not be switched: '.AppHealth::trimReason($e->getMessage()));
    }

    /**
     * The rules are saved before the reload that failed: those on the copy's port, read before the
     * undo moves them, or fails to.
     *
     * @return list<int>
     */
    private function rulesOnCopy(RouteSwitch $switch): array
    {
        $copyPort = $this->switched[$this->servedPort] ?? 0;

        return $copyPort === 0 ? [] : array_map(
            static fn (ProxyRule $rule): int => (int) $rule->id,
            $switch->rulesTo([$copyPort])
        );
    }

    /**
     * Rules may still point at the copy: it stays, recorded with those rule ids, so the sweep moves them
     * back before removing it.
     *
     * @param  list<int>  $leftOnCopy
     * @return array{stdout: string, stderr: string, exit_code: int, copy_kept: true, serving: string}
     */
    private function keepCopyAfterFailedSwitch(GenerationState $state, string $nextProject, array $leftOnCopy, \Throwable $e, \Throwable $undo): array
    {
        $reason = 'the webserver could not be switched, and moving the rules back failed too: '
            .AppHealth::trimReason($e->getMessage()).' / '.AppHealth::trimReason($undo->getMessage());
        $this->logger->warn($reason);
        $state->put(GenerationState::NEXT, [
            'project' => $nextProject,
            'routed' => $this->routedPort,
            'routes' => $this->switched,
            'rules' => $leftOnCopy,
            'back' => $this->servedPort,
        ]);

        return [
            'stdout' => '',
            'stderr' => "The new version could not be switched to: {$reason}. "
                .'Its second copy keeps serving the site until the rules can be moved back to the previous version.',
            'exit_code' => 1,
            self::COPY_KEPT => true,
            self::SERVING => 'A failed route switch left the new version\'s second copy serving the site; nothing was torn down',
        ];
    }

    /** @return list<string> */
    public function generationServices(): array
    {
        return $this->next->generationServices($this->project->userAppDirPath());
    }

    /**
     * Once the replaced app answers on its own port, traffic goes there: the new version's port. When it
     * does not, or the replace failed and the running version no longer answers, the copy serves while the
     * previous version is started again ({@see rollBack()}). Null once traffic is on the app, else the
     * failed start the deploy reports.
     *
     * @return ?array{stdout: string, stderr: string, exit_code: int, previous_kept?: true, copy_kept?: true, serving: string}
     */
    public function finish(bool $replaced, string $output = ''): ?array
    {
        if (! $replaced) {
            return $this->replaceFailed($output);
        }
        if ($this->movedRules !== []) {
            $container = $this->canonicalContainer();
            $failure = $this->awaitAnswer($this->routedPort, $container);
            if ($failure !== null) {
                $this->logger->warn("The replaced app does not answer on port {$this->routedPort}: {$failure}. The second copy keeps serving while the previous version is started again");
                $this->recordOutput($container);

                return $this->rollBack("did not answer on its own port {$this->routedPort} once it replaced the running one: {$failure}");
            }
        }
        if (! $this->moveTrafficBack(self::inverse($this->routes))) {
            return null;
        }
        $this->discard();
        $this->logger->info("Traffic is back on the app's own port {$this->routedPort}; the second copy was removed");

        return null;
    }

    /**
     * Safe to call twice. A failed switch back leaves the second copy serving for the sweep, and so does
     * a port that no longer answers: traffic never goes back to nothing.
     */
    public function abandon(): void
    {
        if ($this->onlyTheCopyServes()) {
            $this->logger->warn("Traffic stays on the second copy: nothing answers on port {$this->servedPort}");

            return;
        }
        if (! $this->moveTrafficBack(self::inverse($this->switched))) {
            return;
        }
        $this->discard();
    }

    /** @return ?array{stdout: string, stderr: string, exit_code: int, previous_kept?: true, copy_kept?: true, serving: string} */
    private function replaceFailed(string $output): ?array
    {
        if (! $this->onlyTheCopyServes()) {
            $this->abandon();

            return null;
        }
        $this->logger->warn("Replacing the running app failed and nothing answers on port {$this->servedPort}; the second copy keeps serving while the previous version is started again");

        return $this->rollBack('could not replace the running app ('.AppHealth::trimReason(FailureOutput::withoutNoise($output))
            ."), and nothing answers on port {$this->servedPort} any more");
    }

    /** The site's rules are on the copy, and the port they would go back to does not answer. */
    private function onlyTheCopyServes(): bool
    {
        return $this->movedRules !== [] && self::answersWithin($this->project, $this->servedPort, self::BACK_SECONDS) !== null;
    }

    /**
     * False when the rules could not be moved back yet, and the second copy keeps serving.
     *
     * @param  array<int, int>  $map
     */
    private function moveTrafficBack(array $map): bool
    {
        try {
            $this->restoreRoutes($map);
        } catch (\Throwable $e) {
            $this->logger->warn('Traffic could not be moved back yet, so the second copy keeps serving: '.AppHealth::trimReason($e->getMessage()));

            return false;
        }

        return true;
    }

    /**
     * A second copy holds the site: the switch moved the site's rules to it and nothing has settled it since.
     * While it does, nothing routes the site elsewhere; {@see GenerationSweep::settleNext()} decides, by
     * what answers, when it goes.
     */
    public static function copyHeld(string $username): bool
    {
        try {
            $next = (new GenerationState($username))->get(GenerationState::NEXT);
        } catch (\Throwable) {
            return false;
        }

        return ($next['rules'] ?? []) !== [] && ($next['routes'] ?? []) !== [];
    }

    /**
     * On a failed start while a second copy holds the site: it keeps serving, and the deploy tears nothing down.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    public static function withHeldCopy(string $username, array $result): array
    {
        if (($result['exit_code'] ?? 0) === 0 || ! self::copyHeld($username) || self::keptServing($result) !== null) {
            return $result;
        }

        return $result + [
            self::COPY_KEPT => true,
            self::SERVING => 'The second copy an earlier redeploy left keeps serving the site; nothing was torn down',
        ];
    }

    /**
     * The deploy log's line for a failed start that left a version serving, else null: nothing serves,
     * and the deploy tears down as usual.
     *
     * @param  array<string, mixed>  $result
     */
    public static function keptServing(array $result): ?string
    {
        if (empty($result[self::PREVIOUS_KEPT]) && empty($result[self::COPY_KEPT])) {
            return null;
        }

        return is_string($result[self::SERVING] ?? null) ? $result[self::SERVING] : 'The previous version is still serving; nothing was torn down';
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
     * @param  array<string, mixed>  $config  `docker compose config --format json`
     * @return list<string>
     */
    public static function servicesToPull(array $config): array
    {
        $services = is_array($config['services'] ?? null) ? $config['services'] : [];
        $built = self::builtImages($services, (string) ($config['name'] ?? ''));
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

    /**
     * @param  array<mixed>  $services
     * @return list<string> the images those services build, without `:latest`
     */
    private static function builtImages(array $services, string $project): array
    {
        $built = [];
        foreach ($services as $name => $service) {
            if (is_array($service) && isset($service['build'])) {
                $built[] = self::withoutLatest((string) ($service['image'] ?? "{$project}-{$name}"));
            }
        }

        return $built;
    }

    private static function withoutLatest(string $image): string
    {
        return str_ends_with($image, ':latest') ? substr($image, 0, -strlen(':latest')) : $image;
    }

    /** Remove a second generation's containers and its override. */
    public static function discardProject(DindProject $project, string $nextProject): void
    {
        $script = 'ids=$(docker ps -aq --filter "label=com.docker.compose.project=$1"); '
            .'[ -z "$ids" ] || docker rm -f -v $ids >/dev/null; rm -f "$2"';
        $project->shell()->execQuiet(['bash', '-c', $script, 'discard', $nextProject, self::OVERRIDE_FILE], [], 120);
    }

    /**
     * What the switch moves: the port the site is on now to the copy of the
     * routed port. Rules to the app's other ports are not the site's route.
     *
     * @param  array<int, int>  $routes  the new version's published port => the second copy's
     * @return array<int, int>
     */
    public static function switchMap(array $routes, int $routed, int $served): array
    {
        return isset($routes[$routed]) ? [$served => $routes[$routed]] : [];
    }

    /**
     * @param  array<int, int>  $routes
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

    /**
     * Why "<status> <exit code> <restarts>" from `docker inspect` will not answer; null while it may.
     * A container running again after a restart has its exit code reset, so restarts count instead.
     */
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
        if ($status === 'created' && in_array($exit, self::COMMAND_FAILED_EXIT_CODES, true)) {
            return "it could not be started (exit code {$exit})";
        }
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

    private static function repositoryCompose(DindProject $project): bool
    {
        return in_array($project->userModel()->getDeployStrategy(), [Strategies::COMPOSE, Strategies::PAEMD], true);
    }

    /** Null once written, else why not. */
    private function writeOverride(): ?string
    {
        try {
            $this->project->shell()->execQuiet(
                ['sh', '-c', 'printf %s "$1" | base64 -d > "$2"', 'override', base64_encode($this->next->override()), self::OVERRIDE_FILE],
                [],
                self::QUICK_TIMEOUT_SECONDS
            );
        } catch (\Throwable $e) {
            return AppHealth::trimReason($e->getMessage());
        }

        return null;
    }

    /** Null once the second copy started, else compose's output. */
    private function startCopy(bool $recreate): ?string
    {
        $shell = $this->project->shell();
        $process = $shell->streamProcess(
            $shell->wrap($this->project->userAppComposeCommand([
                '-f', self::OVERRIDE_FILE, '--project-name', $this->next->projectName(),
                'up', '-d', '--no-deps', '--no-build', '--pull', 'never', ...($recreate ? ['--force-recreate'] : []), $this->next->service,
            ])),
            [],
            self::COMPOSE_TIMEOUT_SECONDS,
            $this->logger
        );
        $this->logger->throwIfCancelled();

        return $process->getExitCode() === 0 ? null : ($process->getErrorOutput() ?: $process->getOutput());
    }

    /**
     * The second copy again, from the realigned run file: null once it started, else what {@see begin()} returns.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}|null
     */
    private function restartRealigned(): string|array|null
    {
        $next = self::planNext($this->project, self::composeConfig($this->project), $this->routedPort);
        if (is_string($next)) {
            return $this->inPlace("the second copy could not follow the port the new version binds: {$next}");
        }
        $this->next = $next;
        $failure = $this->writeOverride();
        if ($failure !== null) {
            return $this->inPlace("its override could not be written: {$failure}");
        }
        $this->logger->info("Starting the new version beside the running one again, on the port it binds (docker compose -p {$next->projectName()} up -d {$next->service})");
        $failure = $this->startCopy(true);

        return $failure === null ? null : $this->notStarted('the second copy did not start again', $failure);
    }

    /**
     * The guess between equal ports no deploy settled, made again on the copy before it is probed: the site goes
     * where a first deploy's health check would send it ({@see AnsweringPort::better()}). Null once decided,
     * else why the copy will not answer.
     */
    private function chooseOnCopy(?string $container): ?string
    {
        $choice = $this->choice;
        if ($choice === null || $container === null) {
            return null;
        }
        $choice['alternatives'] = array_values(array_filter($choice['alternatives'], fn (int $port): bool => isset($this->routes[$port])));
        if ($choice['alternatives'] === []) {
            return null;
        }

        return $this->askCopy($choice, $container);
    }

    /** @param array{primary: int, alternatives: list<int>} $choice */
    private function askCopy(array $choice, string $container): ?string
    {
        $ports = [$this->routedPort, ...$choice['alternatives']];
        $this->logger->info('Asking the new version which of ports '.implode(', ', $ports).' serves the site: no deploy found it with what serves behind them now');
        $deadline = Carbon::now()->getTimestamp() + $this->startPeriod();
        $answeredSince = null;
        while (true) {
            $this->logger->throwIfCancelled();
            $results = $this->probeCopy($ports);
            if (AnsweringPort::servesAPage($results[0]['http_code'])) {
                return null;
            }
            $now = Carbon::now()->getTimestamp();
            $codes = array_column($results, 'http_code');
            if (array_filter($codes) !== []) {
                $answeredSince ??= $now;
            }
            if ($answeredSince !== null && (! self::anyStillStarting($codes) || $now - $answeredSince >= self::CHOICE_SECONDS)) {
                $this->routeToBetter($choice, $results);

                return null;
            }
            $dead = $this->failureOf($container);
            if ($dead !== null) {
                return $dead;
            }
            if ($answeredSince === null && $now >= $deadline) {
                return 'nothing answered within '.$this->startPeriod()." s ({$results[0]['detail']})";
            }
            Sleep::sleep(self::POLL_SECONDS);
        }
    }

    /**
     * @param  list<int>  $ports  published ports, probed on the copy's
     * @return list<array{port: int, http_code: ?int, detail: string}>
     */
    private function probeCopy(array $ports): array
    {
        $results = [];
        foreach ($ports as $published) {
            $answer = self::probe($this->project, $this->routes[$published]);
            $results[] = ['port' => $published, 'http_code' => $answer['http_code'], 'detail' => $answer['detail']];
        }

        return $results;
    }

    /**
     * A silent port, or one answering what a starting app answers: it gets the window to answer otherwise.
     *
     * @param  list<?int>  $codes
     */
    private static function anyStillStarting(array $codes): bool
    {
        return array_filter($codes, static fn (?int $code): bool => $code === null || in_array($code, [500, 502, 503, 504], true)) !== [];
    }

    /**
     * @param  array{primary: int, alternatives: list<int>}  $choice
     * @param  list<array{port: int, http_code: ?int, detail: string}>  $results
     */
    private function routeToBetter(array $choice, array $results): void
    {
        $better = AnsweringPort::better($choice, $results);
        if ($better === null) {
            return;
        }
        $this->project->networking()->routeTo($this->project->userModel(), $better['port']);
        $this->logger->info("Routing the site to port {$better['port']} instead of {$this->routedPort}: on the new version {$better['reason']}");
        $this->routedPort = $better['port'];
    }

    /**
     * Compose failed to start the copy: the new version's own failure when Docker could not run its command
     * (an entrypoint the image lacks), else replace in place.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    private function notStarted(string $why, string $output): string|array
    {
        $container = $this->containerOf($this->next->projectName());
        $failure = $container === null ? null : $this->failureOf($container);

        return $failure === null
            ? $this->inPlace("{$why}: ".AppHealth::trimReason(FailureOutput::withoutNoise($output)))
            : $this->refuse($failure, $container, $output);
    }

    /**
     * A copy that stopped before it could be probed: refused as the gate refuses one that never answers.
     * Compose's own error goes along, for the explainer.
     *
     * @return array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    private function refuse(string $failure, ?string $container, string $output = ''): array
    {
        $this->logger->warn("The new version did not become healthy: {$failure}");
        $this->recordOutput($container);
        $this->discard();

        return [
            'stdout' => '',
            'stderr' => rtrim("The new version did not become healthy: {$failure}. The previous version is still serving.\n".$output),
            'exit_code' => 1,
            self::PREVIOUS_KEPT => true,
        ];
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

    /** Null once $port serves a page within $seconds, else what it said last. */
    public static function answersWithin(DindProject $project, int $port, int $seconds): ?string
    {
        $deadline = Carbon::now()->getTimestamp() + $seconds;
        while (true) {
            $answer = self::probe($project, $port);
            if ($answer['status'] === AppHealth::STATUS_OK) {
                return null;
            }
            if (Carbon::now()->getTimestamp() >= $deadline) {
                return $answer['http_code'] !== null ? "it answers {$answer['detail']}" : $answer['detail'];
            }
            Sleep::sleep(self::POLL_SECONDS);
        }
    }

    /**
     * One port's probe result as the gate reads it: below 500 and not a blank
     * page. An empty 200 answers, but there is nothing to move traffic to.
     *
     * @param  array{status: string, http_code: ?int, detail: string, body?: string}  $result  one {@see AppHealth::parseProbeOutput()} entry
     * @return array{status: string, http_code: ?int, detail: string}
     */
    public static function gateAnswer(array $result): array
    {
        $code = $result['http_code'];
        if ($result['status'] === AppHealth::STATUS_OK && AppHealth::isBlankPage((int) $code, $result['body'] ?? '')) {
            return ['status' => AppHealth::STATUS_FAIL, 'http_code' => $code, 'detail' => "HTTP {$code} ".self::EMPTY_PAGE];
        }

        return ['status' => $result['status'], 'http_code' => $code, 'detail' => $result['detail']];
    }

    /**
     * What the redeploy fails with when the gate turned the new version away.
     * One serving an empty page did start, so it is not called unhealthy.
     */
    public static function refusal(string $failure): string
    {
        if (str_contains($failure, self::EMPTY_PAGE)) {
            return DeployFailureExplainer::EMPTY_NEW_VERSION.', so the site was not moved to it and the previous version is still serving. '
                .'What the new version printed is in the deploy log. A route meant to send nothing answers 204.';
        }

        return "The new version did not become healthy: {$failure}. The previous version is still serving.";
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
            return ['status' => AppHealth::STATUS_FAIL, 'http_code' => null, 'detail' => 'the probe could not run: '.AppHealth::trimReason($e->getMessage())];
        }

        return self::gateAnswer(AppHealth::parseProbeOutput($raw, [$port])[0]);
    }

    /**
     * Null once $port serves a page, else why it will not. On Laravel's clock and sleep, so a test runs
     * these windows without waiting them out.
     */
    private function awaitAnswer(int $port, ?string $container): ?string
    {
        $deadline = Carbon::now()->getTimestamp() + $this->startPeriod();
        $erroringSince = null;
        while (true) {
            $this->logger->throwIfCancelled();
            $answer = self::probe($this->project, $port);
            if ($answer['status'] === AppHealth::STATUS_OK) {
                return null;
            }
            $dead = $container === null ? 'its container is gone' : $this->failureOf($container);
            if ($dead !== null) {
                return $dead;
            }
            $now = Carbon::now()->getTimestamp();
            if ($answer['http_code'] !== null) {
                $erroringSince ??= $now;
                if ($now - $erroringSince >= self::SERVER_ERROR_SECONDS) {
                    return "it answers {$answer['detail']}";
                }
            }
            if ($now >= $deadline) {
                return 'nothing answered within '.$this->startPeriod()." s ({$answer['detail']})";
            }
            Sleep::sleep(self::POLL_SECONDS);
        }
    }

    private function startPeriod(): int
    {
        $declared = $this->project->userModel()->getDetails()[AppHealth::DETAIL_START_PERIOD] ?? null;

        return is_int($declared) ? max(30, $declared) : self::START_PERIOD_SECONDS;
    }

    /** Why $container will not answer, from {@see containerFailure()}; null while it may. */
    private function failureOf(string $container): ?string
    {
        return self::containerFailure($this->inspect($container));
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
                '--filter', 'label=com.docker.compose.project='.$composeProject,
                '--filter', 'label=com.docker.compose.service='.$this->next->service,
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
                $out = $this->project->shell()->execQuiet(['docker', 'port', $container, $target.'/tcp'], [], self::QUICK_TIMEOUT_SECONDS);
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
                ['sh', '-c', 'docker logs --tail '.self::LOG_LINES.' "$1" 2>&1', 'logs', $container],
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

    /**
     * The rules off the copy, recorded at once: it no longer holds the site, even should removing it fail.
     *
     * @param  array<int, int>  $map
     */
    private function restoreRoutes(array $map): void
    {
        if ($this->movedRules === []) {
            return;
        }
        (new RouteSwitch($this->project->system(), $this->project->username()))->move($map, $this->movedRules);
        $this->movedRules = [];
        $this->updateNext(['rules' => []]);
        sleep(self::DRAIN_SECONDS);
    }

    /** Left recorded when it fails, for the sweep to retry. */
    private function discard(): void
    {
        try {
            self::discardProject($this->project, $this->next->projectName());
        } catch (\Throwable $e) {
            Log::warning("Could not remove the second generation of {$this->project->username()}: ".$e->getMessage());

            return;
        }
        (new GenerationState($this->project->username()))->forget(GenerationState::NEXT);
    }

    /**
     * The new version replaced the running one and serves only from its second copy: the previous version
     * is started again from what the redeploy kept, as the sweep does after an interrupted one, and the
     * site moves to it once it answers. Until then, and when it cannot be started, the copy serves.
     *
     * @return array{stdout: string, stderr: string, exit_code: int, previous_kept?: true, copy_kept?: true, serving: string}
     */
    private function rollBack(string $why): array
    {
        $previous = new PreviousVersion($this->project);
        $failure = $previous->restorable() ? $previous->start() : 'its checkout or images are no longer kept';
        if ($failure === null) {
            try {
                $this->restoreRoutes(self::inverse($this->switched));
            } catch (\Throwable $e) {
                $failure = 'the site could not be moved to it: '.AppHealth::trimReason($e->getMessage());
            }
        }
        if ($failure !== null) {
            return $this->keepCopy($why, $failure);
        }
        (new RoutingSnapshot($this->project))->routeBack();
        $this->discard();
        $this->logger->info("The previous version was started again and answers on port {$this->servedPort}; traffic moved to it and the second copy was removed");

        return [
            'stdout' => '',
            'stderr' => "The new version {$why}. The previous version was started again and serves on port {$this->servedPort}.",
            'exit_code' => 1,
            self::PREVIOUS_KEPT => true,
            self::SERVING => 'The previous version was started again and serves; the new version\'s second copy was removed',
        ];
    }

    /**
     * Nothing else answers: the copy keeps the site, and moves back only to the app's port once that
     * answers ({@see GenerationSweep::settleNext()}). The routing snapshot goes: nothing is left to restore,
     * and nothing may route the site away from the copy.
     *
     * @return array{stdout: string, stderr: string, exit_code: int, copy_kept: true, serving: string}
     */
    private function keepCopy(string $why, string $failure): array
    {
        $copy = $this->switched[$this->servedPort] ?? 0;
        $back = $this->project->userModel()->getAppPort() ?? $this->routedPort;
        $this->updateNext(['back' => $back]);
        (new GenerationState($this->project->username()))->forget(GenerationState::ROUTES);
        $this->logger->warn("The previous version could not be started again: {$failure}. The new version's second copy keeps serving the site on port {$copy} until the app answers on port {$back}");

        return [
            'stdout' => '',
            'stderr' => "The new version {$why}. The previous version could not be started again ({$failure}), "
                ."so the new version's second copy keeps serving the site on port {$copy} until the app answers on port {$back}.",
            'exit_code' => 1,
            self::COPY_KEPT => true,
            self::SERVING => 'The new version\'s second copy keeps serving the site; nothing was torn down',
        ];
    }

    /** @param array<string, mixed> $fields set on the NEXT entry, when there is one */
    private function updateNext(array $fields): void
    {
        $state = new GenerationState($this->project->username());
        $next = $state->get(GenerationState::NEXT);
        if ($next !== null) {
            $state->put(GenerationState::NEXT, $fields + $next);
        }
    }

    private function inPlace(string $reason): string
    {
        $this->logger->warn("Replacing the running app in place after all: {$reason}");
        $this->discard();

        return self::IN_PLACE;
    }
}
