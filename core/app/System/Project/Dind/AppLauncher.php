<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\DeployLog\DependencyFailure;
use App\Lib\Deploy\Dind\DindBuildStorage;
use App\Lib\Deploy\DeployLog\FailureOutput;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Port\ComposePortScan;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Strategies;
use App\Lib\Deploy\Platform\Runtime\HostRunProject;
use App\Lib\Deploy\Platform\Runtime\Images;
use App\Lib\Deploy\Platform\Runtime\StandaloneNodeServe;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\Generation\CheckoutAside;
use App\System\Project\Dind\Generation\RoutingSnapshot;
use App\System\Project\Dind\Generation\ZeroDowntimeRedeploy;
use Symfony\Component\Process\Process;

/**
 * Inner `docker compose up` for clone/push and other App\System callers.
 */
final class AppLauncher
{
    private const COMPOSE_TIMEOUT_SECONDS = 3600;

    private const FAILURE_LOG_LINES = 60;

    private const FAILURE_LOG_TIMEOUT_SECONDS = 30;

    private const PS_TIMEOUT_SECONDS = 30;

    // A gate that waits or retries before its verdict (Manticore's credential
    // loop, SOGo's 249 s probe) is still running when `up -d` returns.
    private const ONE_SHOT_WAIT_SECONDS = 180;

    private const ONE_SHOT_POLL_SECONDS = 2;

    /**
     * Set on start()'s result when the Procfile release failed: nothing of the
     * new version was started, so whatever was running before is left as it was.
     */
    public const RELEASE_FAILED = 'release_failed';

    /** Set with RELEASE_FAILED: whether any app container was running before the release (null: unknown). */
    public const RAN_BEFORE_RELEASE = 'ran_before_release';

    public function __construct(
        private DindProject $project,
    ) {
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     * @throws \Exception
     */
    public function start(): array
    {
        return $this->project->registryLogin()->during(fn (): array => $this->startWithLogins());
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     * @throws \Exception
     */
    private function startWithLogins(): array
    {
        $model = $this->project->userModel();
        // A deploy starts the app, so a stop asked for earlier no longer holds.
        $model->markAppStoppedByRequest(false);
        $strategy = $model->getDeployStrategy();
        $runtime = $model->getDeployRuntime();
        $skipBuild = DeployCompose::skipBuild($strategy, $runtime);

        $this->preloadImages($strategy, $runtime, $skipBuild, $model->getDeployImage());
        if ($strategy === Strategies::COMPOSE || $strategy === Strategies::PAEMD) {
            (new BindSourceOwners($this->project))->apply();
        }

        // A redeploy gets every image before anything is replaced: a tag that
        // does not exist fails here, with the running version untouched.
        if (ZeroDowntimeRedeploy::canonicalId($this->project, null) !== null) {
            $pulled = $this->pullImages();
            if ($pulled !== null) {
                return $pulled;
            }
        }

        $release = $this->runRelease($skipBuild);
        if ($release !== null) {
            return $release;
        }

        // A checkout moved aside is still what the running containers read:
        // recreate them onto the new one, as a stop before the wipe did.
        $forceRecreate = DeployCompose::forceRecreate($strategy, $runtime)
            || CheckoutAside::bindsMovedAside($this->project->username());
        $reload = fn () => $this->preloadImages($strategy, $runtime, $skipBuild, $model->getDeployImage(), afterReclaim: true);

        $swap = ZeroDowntimeRedeploy::plan($this->project);
        if ($swap !== null) {
            try {
                $begun = $this->beginSwap($swap, $skipBuild, $reload);
            } catch (\Throwable $e) {
                $swap->abandon();
                throw $e;
            }
            if (is_array($begun)) {
                return $begun;
            }
            if ($begun === ZeroDowntimeRedeploy::IN_PLACE) {
                $swap = null;
            }
        }

        if ($swap !== null) {
            try {
                $process = $this->replaceBehind($swap, $forceRecreate);
                $swap->finish($process->getExitCode() === 0);
            } catch (\Throwable $e) {
                $swap->abandon();
                throw $e;
            }
        } else {
            $command = $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans']);
            if ($forceRecreate) {
                $command[] = '--force-recreate';
            }
            if ($skipBuild) {
                $command[] = '--no-build';
            } else {
                $command[] = '--build';
            }
            $command = self::withDockerConfig($command, $this->project->registryLogin()->configDir());

            $process = $this->run($command);
            $process = $this->retryOnceIfDiskFull($process, $command, $reload);
        }
        if ($process->getExitCode() === 0) {
            // The new version has taken over: the site follows it to its port.
            (new RoutingSnapshot($this->project))->apply();
        }

        if ($process->getExitCode() !== 0) {
            $this->recordContainerOutput();
            $cause = $this->failedDependencyCause($process->getErrorOutput() . "\n" . $process->getOutput());
            if ($cause !== '') {
                return [
                    'stdout' => $process->getOutput(),
                    'stderr' => $cause . "\n" . $process->getErrorOutput(),
                    'exit_code' => $process->getExitCode(),
                ];
            }
        }

        if ($process->getExitCode() === 0) {
            $failure = $this->gateFailure();
            if ($failure !== null) {
                $this->recordContainerOutput();

                return [
                    'stdout' => $process->getOutput(),
                    'stderr' => $failure,
                    'exit_code' => 1,
                ];
            }
            $this->project->alignAppPort();
            // Advisory: the deploy has already succeeded, this only says
            // whether the application behind it answers. See {@see AppHealth}.
            $report = $this->project->appHealth()->report();
            // Asked again on the new route, so the remembered verdict is about it.
            if ($report !== null && $this->routeToTheAnsweringPort($report)) {
                $report = $this->project->appHealth()->report();
            }
            // What a rebuild keeps while the app's ports stay as they are.
            if ($report !== null) {
                try {
                    AnsweringPort::remember($this->project, $report);
                } catch (\Throwable) {
                }
            }
            // A version that answered is kept if the deploy dies before it finishes.
            if (($report['healthy'] ?? null) === true) {
                RoutingSnapshot::markGated($this->project->username());
            }
            // `up` returning 0 is not the only way to end up with nothing
            // serving: a container that starts and then dies on its own
            // configuration finishes `partial`, and so does one that answers
            // 5xx. The health report has just named the failure; this pastes
            // what the container printed.
            if ($this->healthSawAFailingContainer()) {
                $this->recordContainerOutput();
            }
            // Same contract, for the certificate the app's URL depends on:
            // recorded so a caller reporting the deploy does not have to
            // assume what "https://" got it. See {@see AppCertificate}.
            $this->project->appCertificate()->remember();
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => self::failureOutput($process),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * A Procfile `release:` line, run once to completion before `up`: its
     * failure is the deploy's, and the app is not started on top of it.
     * Null when there is none or it succeeded; a failed build of its image is
     * returned as a failed start, not flagged as the release's.
     *
     * @return ?array{stdout: string, stderr: string, exit_code: ?int, release_failed?: true, ran_before_release?: ?bool, previous_kept?: true}
     */
    private function runRelease(bool $skipBuild): ?array
    {
        try {
            $compose = ComposeYaml::parse((string) $this->project->system()->filesystem()->fileGetContents(
                $this->project->userAppComposeFileToRun()
            ));
        } catch (\Throwable) {
            return null;
        }
        $service = is_array($compose) ? GeneratedCompose::releaseService($compose) : null;
        if ($service === null) {
            return null;
        }

        // Asked first: the release's own run may start the services it depends on.
        $ranBefore = $this->anythingRunning();
        // Built on its own, so a failed build is reported as one and not as the release.
        $toBuild = $skipBuild ? [] : self::servicesToBuild($compose, $service);
        if ($toBuild !== []) {
            $build = $this->run(
                self::withDockerConfig(
                    $this->project->userAppComposeCommand(array_merge(['build'], $toBuild)),
                    $this->project->registryLogin()->configDir()
                ),
                'Building the image the Procfile release runs in (docker compose build ' . implode(' ', $toBuild) . ')'
            );
            if ($build->getExitCode() !== 0) {
                // Nothing was replaced yet: a running version keeps serving, as when beginSwap()'s build fails.
                return $ranBefore === true
                    ? ZeroDowntimeRedeploy::previousKept($build->getOutput(), self::failureOutput($build), (int) $build->getExitCode())
                    : [
                        'stdout' => $build->getOutput(),
                        'stderr' => self::failureOutput($build),
                        'exit_code' => $build->getExitCode(),
                    ];
            }
        }
        $command = $this->project->userAppComposeCommand(['run', '--rm', '-T', $service]);
        $command = self::withDockerConfig($command, $this->project->registryLogin()->configDir());
        $shell = $this->project->shell();
        $logger = $shell->logger();
        $logger?->info("Running the Procfile release process (docker compose run {$service})");
        $process = $logger === null
            ? $shell->runProcess($command, [], self::COMPOSE_TIMEOUT_SECONDS)
            : $shell->streamProcess($shell->wrap($command), [], self::COMPOSE_TIMEOUT_SECONDS, $logger);
        $logger?->throwIfCancelled();
        if ($process->getExitCode() === 0) {
            return null;
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => "The Procfile release process exited with code {$process->getExitCode()}; the new version was not started.\n"
                . self::failureOutput($process),
            'exit_code' => $process->getExitCode(),
            self::RELEASE_FAILED => true,
            self::RAN_BEFORE_RELEASE => $ranBefore,
        ];
    }

    /**
     * The release service and every service it starts that has a build of
     * its own: what `run --build` used to build.
     *
     * @param array<mixed> $compose
     * @return list<string>
     */
    private static function servicesToBuild(array $compose, string $service): array
    {
        $services = is_array($compose['services'] ?? null) ? $compose['services'] : [];
        $seen = [];
        $build = [];
        $queue = [$service];
        while ($queue !== []) {
            $name = (string) array_shift($queue);
            if (isset($seen[$name]) || !is_array($services[$name] ?? null)) {
                continue;
            }
            $seen[$name] = true;
            if (isset($services[$name]['build'])) {
                $build[] = $name;
            }
            $deps = $services[$name]['depends_on'] ?? [];
            if (is_array($deps)) {
                foreach ($deps as $key => $value) {
                    $queue[] = is_int($key) ? (string) $value : (string) $key;
                }
            }
        }

        return $build;
    }

    private function anythingRunning(): ?bool
    {
        try {
            $out = $this->project->shell()->execAsUserQuiet(
                $this->project->userAppComposeCommand(['ps', '--quiet', '--status', 'running']),
                [],
                self::PS_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return null;
        }

        return trim($out) !== '';
    }

    /**
     * Point a compose command at the deploy's registry logins: it runs as
     * root in the account, so it would otherwise read root's own config.
     *
     * @param list<string> $command an `env ... docker compose` argv
     * @return list<string>
     */
    public static function withDockerConfig(array $command, ?string $configDir): array
    {
        if ($configDir === null || ($command[0] ?? null) !== 'env') {
            return $command;
        }
        array_splice($command, 1, 0, ['DOCKER_CONFIG=' . $configDir]);

        return $command;
    }

    /**
     * The registry images the run file names and the account lacks, pulled
     * while the running version serves. Null when there was nothing missing
     * or it all arrived; otherwise the failed deploy, with that version kept.
     *
     * @return ?array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    private function pullImages(): ?array
    {
        $config = ZeroDowntimeRedeploy::composeConfig($this->project);
        $services = $config === null ? [] : ZeroDowntimeRedeploy::servicesToPull($config);
        if ($services === []) {
            return null;
        }
        $process = $this->run(
            self::withDockerConfig(
                $this->project->userAppComposeCommand(array_merge(['pull', '--policy', 'missing'], $services)),
                $this->project->registryLogin()->configDir()
            ),
            'Pulling the images the new version needs while the running one serves (docker compose pull)'
        );
        if ($process->getExitCode() === 0) {
            return null;
        }

        return ZeroDowntimeRedeploy::previousKept(
            $process->getOutput(),
            "An image the new version needs could not be pulled; the previous version is still serving.\n" . $process->getErrorOutput(),
            (int) $process->getExitCode()
        );
    }

    /**
     * Build while the running version serves; a failed build leaves it alone.
     *
     * @return string|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    private function beginSwap(ZeroDowntimeRedeploy $swap, bool $skipBuild, \Closure $reload): string|array
    {
        $announce = 'Starting application (beside the running one, which serves until the new version answers)';
        if ($skipBuild) {
            $this->project->shell()->logger()?->info($announce);
        } else {
            $command = self::withDockerConfig($this->project->userAppComposeCommand(['build']), $this->project->registryLogin()->configDir());
            $process = $this->retryOnceIfDiskFull($this->run($command, $announce . ': docker compose build'), $command, $reload);
            if ($process->getExitCode() !== 0) {
                return ZeroDowntimeRedeploy::previousKept($process->getOutput(), self::failureOutput($process), (int) $process->getExitCode());
            }
        }

        return $swap->begin();
    }

    /** Behind the second copy: sidecars only when they changed, the app's own services always. */
    private function replaceBehind(ZeroDowntimeRedeploy $swap, bool $forceRecreate): Process
    {
        $process = $this->run(
            self::withDockerConfig(
                $this->project->userAppComposeCommand(['up', '-d', '--remove-orphans', '--no-build']),
                $this->project->registryLogin()->configDir()
            ),
            'Replacing the running app behind the new version (docker compose up -d)'
        );
        if ($process->getExitCode() !== 0 || !$forceRecreate) {
            return $process;
        }
        $services = $swap->generationServices();

        return $this->run(
            self::withDockerConfig(
                $this->project->userAppComposeCommand(array_merge(['up', '-d', '--no-build', '--no-deps', '--force-recreate'], $services)),
                $this->project->registryLogin()->configDir()
            ),
            'Recreating ' . implode(', ', $services) . ' on the new files (docker compose up -d --force-recreate)'
        );
    }

    /**
     * stderr, led by what a failed build step printed. Compose keeps BuildKit's
     * progress on stdout, so stderr alone reads `exit code: 101` and every
     * caller explaining it ends at `build-step-failed`.
     */
    public static function failureOutput(Process $process): string
    {
        $stderr = $process->getErrorOutput();
        if ($process->getExitCode() === 0) {
            return $stderr;
        }
        $step = FailureOutput::failedBuildStep($process->getOutput());

        return $step === '' ? $stderr : $step . "\n" . $stderr;
    }

    /**
     * A service that ran, failed, and left `up -d` exiting 0.
     *
     * Without `--wait`, `up -d` returns once containers are created and
     * started and does not care what exit code a one-shot service produces.
     * So a gate service -- one whose whole job is to refuse the deploy if a
     * post-condition does not hold -- can run, print its refusal, exit 1, and
     * the deploy is still reported successful.
     *
     * Measured on Manticore (supported-apps#297) with authentication
     * deliberately switched off: `ready` exited 1, `up -d` exited 0, the
     * rebuild endpoint returned success, and the account was published with
     * an unauthenticated writable search engine on it. The thing the gate
     * existed to prevent is exactly what shipped. Baikal (#274), Mattermost
     * (#47) and Dolibarr (#307) all rely on a gate to close a
     * first-visitor-wins installer before the port opens.
     *
     * Reading exit codes after the fact rather than passing `--wait`: `--wait`
     * also waits on health checks, so any service with a slow or wrong one
     * would start delaying or failing deploys that pass today. That is a
     * change worth measuring against real projects first, and it is not
     * needed to close this -- the exit codes are already there to read.
     *
     * A one-shot still running when `up -d` returns is waited for, bounded:
     * a gate that sleeps 8 s and exits 1 was read while running, and the
     * deploy was reported successful 8 s before the refusal.
     */
    private function gateFailure(): ?string
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        $runFile = $this->runFile();
        $oneShots = self::oneShotServices($runFile);
        $deadline = time() + self::ONE_SHOT_WAIT_SECONDS;
        $announced = false;
        while (true) {
            try {
                $output = $shell->execAsUserQuiet(
                    $this->project->userAppComposeCommand(['ps', '--all', '--format', 'json']),
                    [],
                    self::PS_TIMEOUT_SECONDS
                );
            } catch (\Throwable) {
                // Never turn "could not ask" into "failed": that would be the same
                // defect pointed the other way.
                return null;
            }
            $running = self::stillRunning($output, $oneShots);
            if ($running === []) {
                break;
            }
            if (time() >= $deadline) {
                $logger?->warn(
                    'One-shot services still running after ' . self::ONE_SHOT_WAIT_SECONDS
                    . 's, their exit code is not checked: ' . implode(', ', $running)
                );
                break;
            }
            if (!$announced) {
                $logger?->info('Waiting for one-shot services to finish: ' . implode(', ', $running));
                $announced = true;
            }
            $logger?->throwIfCancelled();
            sleep(self::ONE_SHOT_POLL_SECONDS);
        }

        $failed = self::failedServices($output);
        // A service with no restart policy is a helper its author let exit (a
        // `wp shell` with no database to reach), not a gate: said, not failed.
        $helpers = array_flip(self::withoutRestartPolicy($runFile));
        foreach (array_intersect_key($failed, $helpers) as $service => $code) {
            $logger?->warn("Service {$service} exited with code {$code}; nothing depends on it, it publishes no port and has no restart policy, so it stays stopped");
        }
        $failed = array_diff_key($failed, $helpers);
        if ($failed === []) {
            return null;
        }

        foreach ($failed as $service => $code) {
            $logger?->info("Service {$service} exited with code {$code}; the deploy is not successful");
        }

        $described = [];
        foreach ($failed as $service => $code) {
            $described[] = "{$service} (exit {$code})";
        }

        return 'A service exited non-zero after the stack came up: ' . implode(', ', $described);
    }

    /**
     * Services that exited non-zero, name => exit code.
     *
     * Its own function, over `docker compose ps --format json`, so the rule
     * can be tested against real compose output with no daemon. Compose
     * writes either one JSON array or one object per line depending on the
     * version, and both are read here.
     *
     * Only `exited`: a service that is running, restarting or created has not
     * reported anything yet, and a one-shot that exited 0 did its job.
     *
     * @return array<string, int>
     */
    public static function failedServices(string $psOutput): array
    {
        $failed = [];
        foreach (self::psRows($psOutput) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $state = strtolower((string) ($row['State'] ?? ''));
            $code = $row['ExitCode'] ?? null;
            $name = (string) ($row['Service'] ?? $row['Name'] ?? '');
            if ($name !== '' && $state === 'exited' && is_int($code) && $code !== 0) {
                $failed[$name] = $code;
            }
        }

        return $failed;
    }

    /**
     * Services the run file gives `restart: "no"` and no published port: a
     * one-shot, whether its author said so or the hardener worked it out.
     *
     * @param array<string, mixed> $compose
     * @return list<string>
     */
    public static function oneShotServices(array $compose): array
    {
        $names = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $name => $service) {
            if (!is_array($service) || !empty($service['ports'])) {
                continue;
            }
            $restart = $service['restart'] ?? null;
            if ($restart === false || (is_string($restart) && strtolower(trim($restart)) === 'no')) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Services the run file gives no restart policy at all: they publish no
     * port and nothing depends on them, so the hardener left them as written ({@see \App\Lib\Deploy\Compose\ServiceHardener::withRestartPolicy()}).
     *
     * @param array<string, mixed> $compose
     * @return list<string>
     */
    public static function withoutRestartPolicy(array $compose): array
    {
        $names = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $name => $service) {
            if (is_array($service) && in_array($service['restart'] ?? null, [null, ''], true)) {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Which of $services `docker compose ps` still shows running.
     *
     * @param list<string> $services
     * @return list<string>
     */
    public static function stillRunning(string $psOutput, array $services): array
    {
        $running = [];
        foreach (self::psRows($psOutput) as $row) {
            $name = (string) ($row['Service'] ?? '');
            if (in_array($name, $services, true) && strtolower((string) ($row['State'] ?? '')) === 'running') {
                $running[] = $name;
            }
        }

        return array_values(array_unique($running));
    }

    /**
     * `docker compose ps --format json` rows: one JSON array or one object
     * per line, depending on the compose version.
     *
     * @return list<array<mixed>>
     */
    private static function psRows(string $psOutput): array
    {
        $whole = json_decode(trim($psOutput), true);
        if (is_array($whole) && array_is_list($whole)) {
            return array_values(array_filter($whole, 'is_array'));
        }
        $rows = [];
        foreach (preg_split('/\R/', $psOutput) ?: [] as $line) {
            $decoded = json_decode(trim($line), true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> the run file, empty when it cannot be read */
    private function runFile(): array
    {
        try {
            $raw = $this->project->system()->filesystem()->fileGetContents(
                $this->project->userAppComposeFileToRun()
            );
            $compose = ComposeYaml::parse((string) $raw);
        } catch (\Throwable) {
            return [];
        }

        return is_array($compose) ? $compose : [];
    }

    /**
     * @param string|null $strategy
     * @param string|null $runtime
     * @param bool $skipBuild
     * @param string|null $resolvedImage
     * @throws \Exception
     */
    private function preloadImages(
        ?string $strategy,
        ?string $runtime,
        bool $skipBuild,
        ?string $resolvedImage = null,
        bool $afterReclaim = false
    ): void {
        $innerDocker = $this->project->innerDocker();
        // First: the reclaim is `docker system prune -af`, which removes every image
        // no container uses -- including a base fetched moments ago, which then has
        // to come from Docker Hub, where panelalpha/* does not exist. The preloads
        // below put back whatever the run file names.
        if (!$afterReclaim && !DeployCompose::skipReclaimBeforeBuild($strategy, $runtime)) {
            $innerDocker->reclaimStorageIfNeeded();
        }

        if ($resolvedImage !== null && $resolvedImage !== '') {
            $innerDocker->ensureImage($resolvedImage);
        }

        // After the ENOSPC reclaim only the images are gone: the host compile's
        // output lives in the account's files, not in Docker.
        if ($runtime === PlatformManifest::RUNTIME_NGINX) {
            if (!$afterReclaim) {
                $this->project->hostCompile()->run();
            }
            $innerDocker->ensureImage(Images::NGINX_IMAGE);
        } elseif (StandaloneNodeServe::isStandaloneStrategy($strategy) || HostRunProject::isStrategy($strategy)) {
            if (!$afterReclaim) {
                $this->project->hostCompile()->run();
            }
            if (HostRunProject::isNode($strategy) || StandaloneNodeServe::isStandaloneStrategy($strategy)) {
                $innerDocker->ensureImage(Images::nodeImage($this->project->userAppDirPath()));
            }
        } elseif (!$skipBuild) {
            $innerDocker->preloadFrameworkBaseImages($strategy, $runtime);
        }
        $innerDocker->preloadComposeImages($this->project->userAppComposeFileToRun());
    }

    /**
     * @param list<string> $command
     * @param \Closure(): void $preload puts back the images the reclaim deletes
     */
    private function retryOnceIfDiskFull(Process $process, array $command, \Closure $preload): Process
    {
        $innerDocker = $this->project->innerDocker();
        $message = $process->getErrorOutput() . "\n" . $process->getOutput();
        if (
            $process->getExitCode() === 0
            || !DindBuildStorage::isDiskFullError($message)
            || !$innerDocker->canAggressivelyReclaim()
        ) {
            return $process;
        }

        $this->project->shell()->logger()?->info(
            'Build hit ENOSPC; reclaiming inner Docker cache and retrying once'
        );
        Telemetry::signal(
            $this->project->userModel()->username,
            'enospc-retry',
            'Build hit ENOSPC; reclaimed the inner Docker cache and retried once'
        );
        $innerDocker->reclaimStorage(true);
        // `docker system prune -af` took every base no container holds, and
        // panelalpha/* exists on no registry compose would ask.
        $preload();

        return $this->run($command);
    }

    /**
     * What the containers compose gave up on printed, to lead the failure
     * instead of `dependency failed to start` (engine#97). Empty when compose
     * named none, or their output could not be read.
     */
    private function failedDependencyCause(string $composeOutput): string
    {
        $shell = $this->project->shell();
        $described = [];
        foreach (DependencyFailure::failed($composeOutput) as $failed) {
            $command = $failed['kind'] === 'container'
                ? ['docker', 'logs', '--tail=' . self::FAILURE_LOG_LINES, $failed['name']]
                : $this->project->userAppComposeCommand(['logs', '--tail=' . self::FAILURE_LOG_LINES, '--no-color', $failed['name']]);
            try {
                $logs = $shell->execAsUserQuiet(array_merge(['sh', '-c', '"$@" 2>&1', 'sh'], $command), [], self::FAILURE_LOG_TIMEOUT_SECONDS);
            } catch (\Throwable) {
                $logs = '';
            }
            $line = DependencyFailure::describe($failed['name'], $failed['state'], DependencyFailure::cause($logs));
            $shell->logger()?->info($line);
            $described[] = $line;
        }

        return implode("\n", $described);
    }

    private function healthSawAFailingContainer(): bool
    {
        return $this->healthSawARestartLoop() || AppHealth::sawServerError($this->project->userModel()->getDetails());
    }

    /**
     * The site was routed before anything ran, to the lowest of the ports one
     * service publishes when nothing else told them apart (Cabernet: 5004, a
     * stream, over 6077, its web UI), or a rebuild kept it on one of the
     * others. When the probe has since shown that port serving no page and
     * exactly one of the others serving one, the site goes there.
     *
     * @param array<string, mixed> $report
     */
    private function routeToTheAnsweringPort(array $report): bool
    {
        $model = $this->project->userModel();
        $routed = $model->getAppPort();
        try {
            $composePath = $this->project->userAppComposeFileForPorts();
            $env = $this->project->environment()->forPortDetection();
            $primary = DetectAppPort::detectAllPorts($composePath, $env)['primary'] ?? null;
            $better = self::betterRoute(
                ComposePortScan::choiceOf($composePath, $env),
                $primary,
                Networking::recipeComposePort($model),
                $routed,
                is_array($report['ports'] ?? null) ? $report['ports'] : []
            );
        } catch (\Throwable $e) {
            return false;
        }
        if ($better === null) {
            return false;
        }

        $this->project->networking()->routeTo($model, $better['port']);
        $this->project->shell()->logger()?->info("Routed the site to {$better['port']} instead of {$routed}: {$better['reason']}");

        return true;
    }

    /**
     * The rule itself: only a `lowest` guess whose routed port, the guess or
     * the equal a rebuild kept, did not bear it out (no answer, or 4xx/5xx),
     * and only to the one other port of the guess that answered 2xx/3xx.
     *
     * @param array{reason: string, alternatives: list<int>}|null $choice
     * @param list<array<string, mixed>> $results the health probe's per-port results
     * @return array{port: int, reason: string}|null
     */
    public static function betterRoute(?array $choice, ?int $primary, ?int $recipePort, ?int $routed, array $results): ?array
    {
        if ($choice === null || $choice['reason'] !== ComposePortScan::CHOSEN_LOWEST
            || $recipePort !== null || $primary === null) {
            return null;
        }
        $equals = array_values(array_unique([$primary, ...$choice['alternatives']]));
        if (!in_array($routed, $equals, true)) {
            return null;
        }

        $codes = [];
        foreach ($results as $result) {
            if (is_array($result) && is_int($result['port'] ?? null)) {
                $codes[$result['port']] = is_int($result['http_code'] ?? null) && $result['http_code'] > 0 ? $result['http_code'] : null;
            }
        }
        $serves = static fn (?int $code): bool => $code !== null && $code >= 200 && $code < 400;
        $routedCode = $codes[$routed] ?? null;
        if ($serves($routedCode)) {
            return null;
        }

        $answering = array_values(array_filter(
            array_diff($equals, [$routed]),
            static fn (int $port): bool => $serves($codes[$port] ?? null)
        ));
        if (count($answering) !== 1) {
            return null;
        }
        $port = $answering[0];
        $was = $routedCode === null ? "{$routed} did not answer" : "{$routed} answered {$routedCode}";

        return ['port' => $port, 'reason' => "{$was}, {$port} answered {$codes[$port]}"];
    }

    private function healthSawARestartLoop(): bool
    {
        $details = $this->project->userModel()->getDetails();
        foreach ((array) ($details[AppHealth::DETAIL_CHECKS] ?? []) as $check) {
            // A container that is up and silent printed why, too (engine#90).
            if (is_array($check) && in_array($check['id'] ?? null, [AppHealth::CHECK_RESTART_LOOPING, SilentPortCheck::ID], true)) {
                return true;
            }
        }

        return false;
    }

    private function recordContainerOutput(): void
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        if ($logger === null) {
            return;
        }

        try {
            $output = $shell->execAsUserQuiet(
                $this->project->userAppComposeCommand([
                    'logs',
                    '--tail=' . self::FAILURE_LOG_LINES,
                    '--no-color',
                    '--timestamps',
                ]),
                [],
                self::FAILURE_LOG_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return;
        }

        $output = trim($output);
        if ($output === '') {
            return;
        }

        $logger->info('Container output at the point of failure:');
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) !== '') {
                $logger->info($line);
            }
        }
    }

    /**
     * @param list<string> $command
     * @throws DeployCancelledException
     */
    private function run(array $command, string $announce = 'Starting application (docker compose up -d)'): Process
    {
        $shell = $this->project->shell();
        $logger = $shell->logger();
        if ($logger === null) {
            return $shell->runProcess($command, [], self::COMPOSE_TIMEOUT_SECONDS);
        }

        $logger->throwIfCancelled();
        $logger->info($announce);
        $process = $shell->streamProcess(
            $shell->wrap($command),
            [],
            self::COMPOSE_TIMEOUT_SECONDS,
            $logger
        );
        $logger->throwIfCancelled();

        return $process;
    }
}
