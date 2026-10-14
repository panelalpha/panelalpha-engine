<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Compose\GeneratedCompose;
use App\Lib\Deploy\DeployLog\DependencyFailure;
use App\Lib\Deploy\DeployLog\DeployLogger;
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

    /** A gate that waits or retries before its verdict (Manticore's credential loop, SOGo's 249 s probe) is still running when `up -d` returns. */
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
     * A copy an earlier redeploy left still serves when a start fails: nothing is torn down under it.
     *
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     * @throws \Exception
     */
    public function start(): array
    {
        return ZeroDowntimeRedeploy::withHeldCopy(
            $this->project->username(),
            $this->project->registryLogin()->during(fn (): array => $this->startWithLogins())
        );
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     * @throws \Exception
     */
    private function startWithLogins(): array
    {
        $model = $this->project->userModel();
        $strategy = $model->getDeployStrategy();
        $runtime = $model->getDeployRuntime();
        $skipBuild = DeployCompose::skipBuild($strategy, $runtime);

        $this->preloadImages($strategy, $runtime, $skipBuild, $model->getDeployImage());
        if ($strategy === Strategies::COMPOSE || $strategy === Strategies::PAEMD) {
            (new BindSourceOwners($this->project))->apply();
        }

        $pulled = $this->pullImages();
        if ($pulled !== null) {
            return $pulled;
        }

        $reloadAfterReclaim = fn () => $this->preloadImages($strategy, $runtime, $skipBuild, $model->getDeployImage(), afterReclaim: true);

        $release = $this->runRelease($skipBuild, $reloadAfterReclaim);
        if ($release !== null) {
            return $release;
        }

        $up = $this->bringUp($skipBuild, $this->mustForceRecreate($strategy, $runtime), $reloadAfterReclaim);

        return is_array($up) ? $up : $this->outcome($up);
    }

    /** A checkout moved aside is still what the running containers read: they are recreated onto the new one, as a stop before the wipe did. */
    private function mustForceRecreate(?string $strategy, ?string $runtime): bool
    {
        return DeployCompose::forceRecreate($strategy, $runtime)
            || CheckoutAside::bindsMovedAside($this->project->username());
    }

    /**
     * `up` beside the running version when a swap is planned, in place otherwise.
     *
     * @param \Closure(): void $reload puts back the images the ENOSPC reclaim deletes
     * @return Process|array{stdout: string, stderr: string, exit_code: ?int} an array ends the start as it is
     */
    private function bringUp(bool $skipBuild, bool $forceRecreate, \Closure $reload): Process|array
    {
        $swap = $this->beginPlannedSwap($skipBuild, $reload);
        if (is_array($swap)) {
            return $swap;
        }
        if ($swap === null) {
            return $this->upInPlace($skipBuild, $forceRecreate, $reload);
        }

        return $this->replaceAndFinish($swap, $forceRecreate);
    }

    /**
     * The planned swap once its build is done; null when there is none or the redeploy goes in place.
     *
     * @return ZeroDowntimeRedeploy|array{stdout: string, stderr: string, exit_code: int, previous_kept: true}|null
     */
    private function beginPlannedSwap(bool $skipBuild, \Closure $reload): ZeroDowntimeRedeploy|array|null
    {
        $swap = ZeroDowntimeRedeploy::plan($this->project);
        if ($swap === null) {
            return null;
        }
        try {
            $begun = $this->beginSwap($swap, $skipBuild, $reload);
        } catch (\Throwable $e) {
            $swap->abandon();
            throw $e;
        }
        if (is_array($begun)) {
            return $begun;
        }

        return $begun === ZeroDowntimeRedeploy::IN_PLACE ? null : $swap;
    }

    /**
     * When the replaced app never answered, the previous version or the copy serves instead and that result is returned.
     *
     * @return Process|array{stdout: string, stderr: string, exit_code: ?int}
     */
    private function replaceAndFinish(ZeroDowntimeRedeploy $swap, bool $forceRecreate): Process|array
    {
        try {
            $process = $this->replaceBehind($swap, $forceRecreate);
            $rolledBack = $swap->finish($process->getExitCode() === 0, self::failureOutput($process));
        } catch (\Throwable $e) {
            $swap->abandon();
            throw $e;
        }

        return $rolledBack ?? $process;
    }

    private function upInPlace(bool $skipBuild, bool $forceRecreate, \Closure $reload): Process
    {
        $flags = $forceRecreate ? ['--force-recreate'] : [];
        $flags[] = $skipBuild ? '--no-build' : '--build';
        $command = $this->composeWithLogins(['up', '-d', '--remove-orphans', ...$flags]);

        return $this->retryOnceIfDiskFull($this->run($command), $command, $reload);
    }

    /**
     * On success the site follows the new version to its port, then gates and health are checked.
     *
     * @return array{stdout: string, stderr: string, exit_code: ?int}
     */
    private function outcome(Process $process): array
    {
        if ($process->getExitCode() !== 0) {
            return $this->failedUp($process);
        }
        (new RoutingSnapshot($this->project))->apply();

        $failure = $this->gateFailure();
        if ($failure !== null) {
            $this->recordContainerOutput();

            return [
                'stdout' => $process->getOutput(),
                'stderr' => $failure,
                'exit_code' => 1,
            ];
        }
        $this->recordServingVerdict();

        return self::result($process);
    }

    /** @return array{stdout: string, stderr: string, exit_code: ?int} */
    private function failedUp(Process $process): array
    {
        $this->recordContainerOutput();
        $cause = $this->failedDependencyCause($process->getErrorOutput() . "\n" . $process->getOutput());
        if ($cause === '') {
            return self::result($process);
        }

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $cause . "\n" . $process->getErrorOutput(),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /**
     * Advisory, after the deploy has succeeded: whether the app answers ({@see AppHealth}), asked again
     * after a reroute so the verdict is about the new route, then the certificate its URL depends on ({@see AppCertificate}).
     */
    private function recordServingVerdict(): void
    {
        $this->project->alignAppPort();
        $report = $this->project->appHealth()->report();
        if ($report !== null && $this->routeToTheAnsweringPort($report)) {
            $report = $this->project->appHealth()->report();
        }
        $this->rememberAnsweringVersion($report);
        if ($this->healthSawAFailingContainer()) {
            $this->recordContainerOutput();
        }
        $this->project->appCertificate()->remember();
    }

    /**
     * Remembers the port a rebuild keeps while the app's ports stay as they are, and marks a version
     * that answered as kept if the deploy dies before it finishes.
     *
     * @param array<string, mixed>|null $report
     */
    private function rememberAnsweringVersion(?array $report): void
    {
        if ($report !== null) {
            try {
                AnsweringPort::remember($this->project, $report);
            } catch (\Throwable) {
            }
        }
        if (($report['healthy'] ?? null) === true) {
            RoutingSnapshot::markGated($this->project->username());
        }
    }

    /** @return array{stdout: string, stderr: string, exit_code: ?int} */
    private static function result(Process $process): array
    {
        return [
            'stdout' => $process->getOutput(),
            'stderr' => self::failureOutput($process),
            'exit_code' => $process->getExitCode(),
        ];
    }

    /** @return array{stdout: string, stderr: string, exit_code: int, previous_kept: true} */
    private static function previousKept(Process $process): array
    {
        return ZeroDowntimeRedeploy::previousKept($process->getOutput(), self::failureOutput($process), (int) $process->getExitCode());
    }

    /**
     * A Procfile `release:` line, run once to completion before `up`: its failure is the deploy's,
     * and the app is not started on top of it. Null when there is none or it succeeded.
     * Whether anything runs is asked first, since the release's own run may start the services it depends on.
     *
     * @param \Closure(): void $reload puts back the images the ENOSPC reclaim deletes
     * @return ?array{stdout: string, stderr: string, exit_code: ?int, release_failed?: true, ran_before_release?: ?bool, previous_kept?: true}
     */
    private function runRelease(bool $skipBuild, \Closure $reload): ?array
    {
        $compose = $this->runFile();
        $service = GeneratedCompose::releaseService($compose);
        if ($service === null) {
            return null;
        }

        $ranBefore = $this->anythingRunning();
        $toBuild = $skipBuild ? [] : self::servicesToBuild($compose, $service);
        if ($toBuild !== []) {
            $failedBuild = $this->buildForRelease($toBuild, $ranBefore, $reload);
            if ($failedBuild !== null) {
                return $failedBuild;
            }
        }

        return $this->runReleaseProcess($service, $ranBefore);
    }

    /**
     * Built on its own, so a failed build is returned as a failed start, not flagged as the release's.
     * An ENOSPC is reclaimed and retried as the `up` and beginSwap() builds are; a running version keeps serving.
     *
     * @param list<string> $services
     * @return ?array{stdout: string, stderr: string, exit_code: ?int, previous_kept?: true}
     */
    private function buildForRelease(array $services, ?bool $ranBefore, \Closure $reload): ?array
    {
        $buildCommand = $this->composeWithLogins(array_merge(['build'], $services));
        $build = $this->retryOnceIfDiskFull(
            $this->run(
                $buildCommand,
                'Building the image the Procfile release runs in (docker compose build ' . implode(' ', $services) . ')'
            ),
            $buildCommand,
            $reload
        );
        if ($build->getExitCode() === 0) {
            return null;
        }

        return $ranBefore === true ? self::previousKept($build) : self::result($build);
    }

    /** @return ?array{stdout: string, stderr: string, exit_code: ?int, release_failed: true, ran_before_release: ?bool} */
    private function runReleaseProcess(string $service, ?bool $ranBefore): ?array
    {
        $process = $this->run(
            $this->composeWithLogins(['run', '--rm', '-T', $service]),
            "Running the Procfile release process (docker compose run {$service})"
        );
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
            array_push($queue, ...self::dependencyNames($services[$name]['depends_on'] ?? []));
        }

        return $build;
    }

    /** @return list<string> the services a `depends_on` names, in its list or map form */
    private static function dependencyNames(mixed $dependsOn): array
    {
        if (!is_array($dependsOn)) {
            return [];
        }
        $names = [];
        foreach ($dependsOn as $key => $value) {
            $names[] = is_int($key) ? (string) $value : (string) $key;
        }

        return $names;
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
     * @param list<string> $args
     * @return list<string>
     */
    private function composeWithLogins(array $args): array
    {
        return self::withDockerConfig($this->project->userAppComposeCommand($args), $this->project->registryLogin()->configDir());
    }

    /**
     * On a redeploy, the registry images the run file names and the account lacks, pulled while the running
     * version serves: a missing tag fails here, with that version untouched. Null when nothing was missing or it all arrived.
     *
     * @return ?array{stdout: string, stderr: string, exit_code: int, previous_kept: true}
     */
    private function pullImages(): ?array
    {
        if (ZeroDowntimeRedeploy::canonicalId($this->project, null) === null) {
            return null;
        }
        $config = ZeroDowntimeRedeploy::composeConfig($this->project);
        $services = $config === null ? [] : ZeroDowntimeRedeploy::servicesToPull($config);
        if ($services === []) {
            return null;
        }
        $process = $this->run(
            $this->composeWithLogins(array_merge(['pull', '--policy', 'missing'], $services)),
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
            $command = $this->composeWithLogins(['build']);
            $process = $this->retryOnceIfDiskFull($this->run($command, $announce . ': docker compose build'), $command, $reload);
            if ($process->getExitCode() !== 0) {
                return self::previousKept($process);
            }
        }

        return $swap->begin();
    }

    /** Behind the second copy: sidecars only when they changed, the app's own services always. */
    private function replaceBehind(ZeroDowntimeRedeploy $swap, bool $forceRecreate): Process
    {
        $process = $this->run(
            $this->composeWithLogins(['up', '-d', '--remove-orphans', '--no-build']),
            'Replacing the running app behind the new version (docker compose up -d)'
        );
        if ($process->getExitCode() !== 0 || !$forceRecreate) {
            return $process;
        }
        $services = $swap->generationServices();

        return $this->run(
            $this->composeWithLogins(array_merge(['up', '-d', '--no-build', '--no-deps', '--force-recreate'], $services)),
            'Recreating ' . implode(', ', $services) . ' on the new files (docker compose up -d --force-recreate)'
        );
    }

    /**
     * stderr, led by what a failed build step printed: compose keeps BuildKit's progress on stdout,
     * so stderr alone reads `exit code: 101` and every caller explaining it ends at `build-step-failed`.
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
     * A gate service that ran, failed, and left `up -d` exiting 0, which is not reported without `--wait`
     * (not passed: it also waits on health checks). One-shots still running are waited for, bounded.
     */
    private function gateFailure(): ?string
    {
        $logger = $this->project->shell()->logger();
        $runFile = $this->runFile();
        $output = $this->psOnceOneShotsFinish(self::oneShotServices($runFile), $logger);
        if ($output === null) {
            return null;
        }

        $failed = self::withoutHelpers(self::failedServices($output), $runFile, $logger);
        if ($failed === []) {
            return null;
        }

        return self::describeFailedGates($failed, $logger);
    }

    /**
     * `compose ps --all` once the one-shots stop running or ONE_SHOT_WAIT_SECONDS pass; null when it could
     * not be asked, which never counts as failed.
     *
     * @param list<string> $oneShots
     */
    private function psOnceOneShotsFinish(array $oneShots, ?DeployLogger $logger): ?string
    {
        $deadline = time() + self::ONE_SHOT_WAIT_SECONDS;
        $announced = false;
        while (true) {
            $output = $this->psAll();
            if ($output === null) {
                return null;
            }
            $running = self::stillRunning($output, $oneShots);
            if ($running === []) {
                return $output;
            }
            if (time() >= $deadline) {
                $logger?->warn(
                    'One-shot services still running after ' . self::ONE_SHOT_WAIT_SECONDS
                    . 's, their exit code is not checked: ' . implode(', ', $running)
                );

                return $output;
            }
            if (!$announced) {
                $logger?->info('Waiting for one-shot services to finish: ' . implode(', ', $running));
                $announced = true;
            }
            $logger?->throwIfCancelled();
            sleep(self::ONE_SHOT_POLL_SECONDS);
        }
    }

    private function psAll(): ?string
    {
        try {
            return $this->project->shell()->execAsUserQuiet(
                $this->project->userAppComposeCommand(['ps', '--all', '--format', 'json']),
                [],
                self::PS_TIMEOUT_SECONDS
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A failed service with no restart policy is a helper its author let exit (a `wp shell` with no
     * database to reach), not a gate: said, not failed.
     *
     * @param array<string, int> $failed
     * @param array<string, mixed> $runFile
     * @return array<string, int>
     */
    private static function withoutHelpers(array $failed, array $runFile, ?DeployLogger $logger): array
    {
        $helpers = array_flip(self::withoutRestartPolicy($runFile));
        foreach (array_intersect_key($failed, $helpers) as $service => $code) {
            $logger?->warn("Service {$service} exited with code {$code}; nothing depends on it, it publishes no port and has no restart policy, so it stays stopped");
        }

        return array_diff_key($failed, $helpers);
    }

    /** @param array<string, int> $failed */
    private static function describeFailedGates(array $failed, ?DeployLogger $logger): string
    {
        $described = [];
        foreach ($failed as $service => $code) {
            $logger?->info("Service {$service} exited with code {$code}; the deploy is not successful");
            $described[] = "{$service} (exit {$code})";
        }

        return 'A service exited non-zero after the stack came up: ' . implode(', ', $described);
    }

    /**
     * Services that exited non-zero, name => exit code, from `docker compose ps --format json`.
     * Only `exited`: running, restarting or created has reported nothing yet, and exit 0 did its job.
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
     * Services the run file gives no restart policy at all: they publish no port and nothing depends
     * on them, so the hardener left them as written ({@see \App\Lib\Deploy\Compose\ServiceHardener::withRestartPolicy()}).
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
     * Reclaims first, since its prune removes every image no container uses, including a base just fetched
     * that Docker Hub does not have (panelalpha/*); the preloads then put back what the run file names.
     *
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
        if (!$afterReclaim && !DeployCompose::skipReclaimBeforeBuild($strategy, $runtime)) {
            $innerDocker->reclaimStorageIfNeeded();
        }

        if ($resolvedImage !== null && $resolvedImage !== '') {
            $innerDocker->ensureImage($resolvedImage);
        }

        $this->preloadRuntimeImages($innerDocker, $strategy, $runtime, $skipBuild, $afterReclaim);
        $innerDocker->preloadComposeImages($this->project->userAppComposeFileToRun());
    }

    private function preloadRuntimeImages(
        InnerDocker $innerDocker,
        ?string $strategy,
        ?string $runtime,
        bool $skipBuild,
        bool $afterReclaim
    ): void {
        if ($runtime === PlatformManifest::RUNTIME_NGINX) {
            $this->compileOnHost($afterReclaim);
            $innerDocker->ensureImage(Images::NGINX_IMAGE);
        } elseif (StandaloneNodeServe::isStandaloneStrategy($strategy) || HostRunProject::isStrategy($strategy)) {
            $this->compileOnHost($afterReclaim);
            if (HostRunProject::isNode($strategy) || StandaloneNodeServe::isStandaloneStrategy($strategy)) {
                $innerDocker->ensureImage(Images::nodeImage($this->project->userAppDirPath()));
            }
        } elseif (!$skipBuild) {
            $innerDocker->preloadFrameworkBaseImages($strategy, $runtime);
        }
    }

    /** Not again after the ENOSPC reclaim: only images are gone, and the compile's output lives in the account's files. */
    private function compileOnHost(bool $afterReclaim): void
    {
        if (!$afterReclaim) {
            $this->project->hostCompile()->run();
        }
    }

    /**
     * Reclaims and retries once when the build hit ENOSPC; the prune takes every base no container
     * holds, and panelalpha/* exists on no registry compose would ask, so $preload puts them back.
     *
     * @param list<string> $command
     * @param \Closure(): void $preload puts back the images the reclaim deletes
     */
    private function retryOnceIfDiskFull(Process $process, array $command, \Closure $preload): Process
    {
        $innerDocker = $this->project->innerDocker();
        if (!self::isReclaimableDiskFull($process, $innerDocker)) {
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
        $preload();

        return $this->run($command);
    }

    private static function isReclaimableDiskFull(Process $process, InnerDocker $innerDocker): bool
    {
        return $process->getExitCode() !== 0
            && DindBuildStorage::isDiskFullError($process->getErrorOutput() . "\n" . $process->getOutput())
            && $innerDocker->canAggressivelyReclaim();
    }

    /**
     * What the containers compose gave up on printed, to lead the failure
     * instead of `dependency failed to start`. Empty when compose
     * named none, or their output could not be read.
     */
    private function failedDependencyCause(string $composeOutput): string
    {
        $shell = $this->project->shell();
        $described = [];
        foreach (DependencyFailure::failed($composeOutput) as $failed) {
            $logs = $this->failedDependencyLogs($failed['kind'], $failed['name']);
            $probe = $failed['state'] === 'unhealthy' ? DependencyFailure::probe($this->failedDependencyHealth($failed['name'])) : '';
            $line = DependencyFailure::describe($failed['name'], $failed['state'], DependencyFailure::cause($logs), $probe);
            $shell->logger()?->info($line);
            $described[] = $line;
        }

        return implode("\n", $described);
    }

    /** The last lines a failed container or compose service printed; empty when they could not be read. */
    private function failedDependencyLogs(string $kind, string $name): string
    {
        $command = $kind === 'container'
            ? ['docker', 'logs', '--tail=' . self::FAILURE_LOG_LINES, $name]
            : $this->project->userAppComposeCommand(['logs', '--tail=' . self::FAILURE_LOG_LINES, '--no-color', $name]);
        try {
            return $this->project->shell()->execAsUserQuiet(array_merge(['sh', '-c', '"$@" 2>&1', 'sh'], $command), [], self::FAILURE_LOG_TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return '';
        }
    }

    /** The container's `.State.Health` as JSON; empty when it could not be read. */
    private function failedDependencyHealth(string $container): string
    {
        try {
            return $this->project->shell()->execAsUserQuiet(
                ['docker', 'inspect', '--format', '{{json .State.Health}}', $container],
                [],
                self::FAILURE_LOG_TIMEOUT_SECONDS,
            );
        } catch (\Throwable) {
            return '';
        }
    }

    private function healthSawAFailingContainer(): bool
    {
        return $this->healthSawARestartLoop() || AppHealth::sawServerError($this->project->userModel()->getDetails());
    }

    /**
     * The site was routed before anything ran, to the lowest port of a service when nothing else told them
     * apart (Cabernet: 5004, a stream, over 6077, its web UI), or a rebuild kept it on another one.
     * When the probe shows that port serving no page and exactly one of the others serving one, the site goes there.
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

        $codes = self::probedCodes($results);
        $routedCode = $codes[$routed] ?? null;
        if (self::servesAPage($routedCode)) {
            return null;
        }

        $answering = array_values(array_filter(
            array_diff($equals, [$routed]),
            static fn (int $port): bool => self::servesAPage($codes[$port] ?? null)
        ));
        if (count($answering) !== 1) {
            return null;
        }
        $port = $answering[0];
        $was = $routedCode === null ? "{$routed} did not answer" : "{$routed} answered {$routedCode}";

        return ['port' => $port, 'reason' => "{$was}, {$port} answered {$codes[$port]}"];
    }

    /**
     * @param list<array<string, mixed>> $results
     * @return array<int, int|null> port => HTTP code, null when it did not answer
     */
    private static function probedCodes(array $results): array
    {
        $codes = [];
        foreach ($results as $result) {
            if (is_array($result) && is_int($result['port'] ?? null)) {
                $codes[$result['port']] = is_int($result['http_code'] ?? null) && $result['http_code'] > 0 ? $result['http_code'] : null;
            }
        }

        return $codes;
    }

    private static function servesAPage(?int $code): bool
    {
        return $code !== null && $code >= 200 && $code < 400;
    }

    /** A restart loop, or a container that is up and silent: either printed why. */
    private function healthSawARestartLoop(): bool
    {
        $details = $this->project->userModel()->getDetails();
        foreach ((array) ($details[AppHealth::DETAIL_CHECKS] ?? []) as $check) {
            if (is_array($check) && in_array($check['id'] ?? null, [AppHealth::CHECK_RESTART_LOOPING, SilentPortCheck::ID], true)) {
                return true;
            }
        }

        return false;
    }

    private function recordContainerOutput(): void
    {
        $logger = $this->project->shell()->logger();
        if ($logger === null) {
            return;
        }

        $output = trim($this->containerLogTail() ?? '');
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

    private function containerLogTail(): ?string
    {
        try {
            return $this->project->shell()->execAsUserQuiet(
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
            return null;
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
