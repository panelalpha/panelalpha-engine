<?php

namespace App\System\Project\Dind\Strategy;

use App\Exceptions\BuildStalledException;
use App\Exceptions\DiskLimitException;
use App\Lib\Deploy\CacheManager\BuildCache;
use App\Lib\Deploy\CacheManager\RailpackCache;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\PlatformManifest;
use App\Lib\Deploy\Platform\Strategies;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind as DindProject;
use App\System\Project\Dind\ShellOperations;
use Illuminate\Support\Facades\Log;

/**
 * The last resort: build with Railpack, else serve a page saying nothing could be built. Only a first deploy gets
 * the page; a failed redeploy or a stopped build fails the deploy, so the running version keeps serving.
 */
class RailpackStrategy
{
    private const BUILD_TIMEOUT_SECONDS = 3600;

    private const PREPARE_TIMEOUT_SECONDS = 900;

    private const DEFAULT_PORT = 80;

    private const SERVING_CHECK_TIMEOUT_SECONDS = 30;

    /** The command Railpack gives an image when its plan names nothing to start. */
    private const IDLE_SHELL = '/bin/bash';

    /** Where a project that builds nothing to run keeps the pages it ships, in the order they are tried. */
    public const STATIC_OUTPUT_DIRS = ['dist', 'public', 'build'];

    private DindProject $dind;

    public function __construct(DindProject $dind)
    {
        $this->dind = $dind;
    }

    public function applyRailpackOrFallback(string $projectDir, ?string $chown, string $sourceLabel): void
    {
        if ($this->tryBuild($projectDir, $chown)) {
            return;
        }
        if ($this->servesAnApp()) {
            throw new \RuntimeException('The Railpack build failed, so the running version was kept. The build output is in the deploy log.');
        }

        $this->dind->shell()->logger()?->info(
            'No compose file and automatic build unavailable, generating basic compose'
        );
        $this->applyAsUnknown($projectDir, $chown, $sourceLabel);
    }

    /** A stack that cannot be listed counts as serving: the placeholder never replaces what may be running. */
    private function servesAnApp(): bool
    {
        try {
            return $this->dind->accountDaemon()->stackContainerIds(timeout: self::SERVING_CHECK_TIMEOUT_SECONDS) !== [];
        } catch (\Throwable $e) {
            Log::info('Could not list the running stack before the Railpack fallback', ['error' => $e->getMessage()]);

            return true;
        }
    }

    /** The placeholder page for a project its detected strategy could not run, recorded as the fallback. */
    public function applyAsUnknown(string $projectDir, ?string $chown, string $sourceLabel): void
    {
        $this->dind->freezeDeploySnapshot([
            'deploy_strategy' => Strategies::FALLBACK,
            'deploy_label' => 'Unknown',
        ]);
        $this->applyFallback($projectDir, $chown, $sourceLabel);
    }

    /** The page saying nothing could be built, a 404 on every path; an index.html the user shipped is served as a static site. */
    public function applyFallback(string $projectDir, ?string $chown, string $sourceLabel): void
    {
        $writer = $this->dind->composeWriter();
        $writer->writeGeneratedCompose($projectDir, DeployCompose::staticNginx(), $chown);

        if ($writer->writeFallbackIndexHtml($projectDir, $sourceLabel, $chown)) {
            $writer->writePlaceholderNginxConf($projectDir, $chown);
        } else {
            $writer->writeStaticNginxConf($projectDir, null, $chown);
        }
    }

    /**
     * The account-writable ~/.panelalpha: unlike /tmp the host reaches it, unlike the root-owned home (SFTP chroot) the
     * account can write there. Re-asserted, as an older or restored account may lack it; the home if it cannot be made.
     */
    private function accountScratchDir(): string
    {
        $home = rtrim($this->dind->homeDirPath(), '/');
        $scratch = $home.'/'.AppConfigDirectory::DIRNAME;
        $system = $this->dind->system();

        if ($system->directoryExists($scratch)) {
            return $scratch;
        }

        try {
            [$uid, $gid] = $this->dind->userModel()->linuxIds();
            $system->exec(Privileged::scripts()->makeHomeEntry($scratch, '0700', $uid.':'.$gid), [], 30);
        } catch (\Throwable $e) {
            Log::info('Could not prepare the railpack scratch directory', ['error' => $e->getMessage()]);

            return $home;
        }

        return $system->directoryExists($scratch) ? $scratch : $home;
    }

    /**
     * Build with Railpack and write a compose file running the image (no bind-mount); false on any failure, for the
     * basic nginx compose. The image is named per account: each has its own inner Docker daemon (sysbox).
     */
    private function tryBuild(string $projectDir, ?string $chown): bool
    {
        $username = $this->dind->userModel()->username;
        $imageName = "panelalpha-{$username}-app:latest";
        if (! $this->buildImage($projectDir, $chown, $imageName)) {
            return false;
        }
        if (! $this->imageStarts($imageName)) {
            return $this->serveStaticOutput($projectDir, $chown);
        }

        $port = $this->imagePort($imageName, $username);
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::railpack($imageName, $port),
            $chown
        );

        Log::info("Railpack build succeeded for {$username}; image={$imageName}, port={$port}.");
        $this->dind->shell()->logger()?->ok('Application image built');

        return true;
    }

    /**
     * Build $projectDir into $imageName in this account. Also what a staging, clone or push uses to build the image
     * a copied run file names from the copied tree.
     */
    public function buildImage(string $projectDir, ?string $chown, string $imageName): bool
    {
        $username = $this->dind->userModel()->username;
        $system = $this->dind->system();
        $shell = $this->dind->shell();
        $logger = $shell->logger();

        try {
            $shell->execAsUser(['which', 'railpack']);
        } catch (\Exception $e) {
            Log::info("Railpack not available in dind container for {$username}, skipping railpack build.");
            $logger?->dim('Railpack not available, skipping automatic build');

            return false;
        }

        $logger?->info('Building application image (railpack + buildx)');

        return $this->buildInScratch($projectDir, $chown, $imageName, $username, $system, $shell);
    }

    /**
     * Prepare, preload and build in the scratch dir. The finally removes the plan and the prepare script whatever
     * happened, so neither is left in the customer's home directory.
     */
    private function buildInScratch(string $projectDir, ?string $chown, string $imageName, string $username, System $system, ShellOperations $shell): bool
    {
        $scratch = $this->accountScratchDir();
        $planPath = $scratch.'/railpack-plan-tmp.json';
        $buildScriptPath = $scratch.'/railpack-build-tmp.sh';

        $prepareScript = self::prepareScript($projectDir, $planPath);

        try {
            $planJson = $this->preparePlan($system, $shell, $prepareScript, $buildScriptPath, $planPath, $chown, $username);
            $this->preloadPlanImages($planJson);
            $build = $this->buildCommand($projectDir, $planPath, $imageName);

            $this->dind->innerBuildGuard()->during(
                fn (): string => $shell->execAsUser($build, [], self::BUILD_TIMEOUT_SECONDS)
            );
        } catch (DiskLimitException|BuildStalledException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::info(
                "Railpack build failed for {$username} (unsupported project or build error), falling back to nginx compose.",
                ['error' => $e->getMessage()]
            );

            return false;
        } finally {
            $this->removeScratchFiles($system, $buildScriptPath, $planPath);
        }

        return true;
    }

    /** As root, which staged and renamed them in; best effort, so the cleanup never replaces the build's outcome. */
    private function removeScratchFiles(System $system, string ...$paths): void
    {
        try {
            $system->exec(Privileged::files()->removeIfExists(...$paths));
        } catch (\Throwable $e) {
            Log::warning('Could not remove the railpack scratch files', ['error' => $e->getMessage()]);
        }
    }

    private static function prepareScript(string $projectDir, string $planPath): string
    {
        return
            '#!/bin/bash'."\n".
            'set -e'."\n".
            'cd '.escapeshellarg($projectDir)."\n".
            'railpack prepare . --plan-out '.escapeshellarg($planPath)."\n";
    }

    private function preparePlan(System $system, ShellOperations $shell, string $prepareScript, string $buildScriptPath, string $planPath, ?string $chown, string $username): ?string
    {
        $system->filesystem()->writeFileReplacingPath($buildScriptPath, $prepareScript, $chown, '700');
        $shell->execAsUser(['bash', $buildScriptPath], [], self::PREPARE_TIMEOUT_SECONDS);

        return $this->readPlan($planPath, $username);
    }

    /**
     * After `railpack prepare`, not before: the builder and runtime tags follow Railpack's own release calendar,
     * and the plan is the only thing that knows which it resolved to.
     */
    private function preloadPlanImages(?string $planJson): void
    {
        $this->dind->innerDocker()->preloadRailpackBaseImages(
            RailpackCache::preloadImages((string) $planJson)
        );
    }

    /**
     * `docker buildx build` of the plan, run from the broker against the account's daemon, with the project's registry
     * logins when it has any.
     *
     * @return list<string>
     */
    private function buildCommand(string $projectDir, string $planPath, string $imageName): array
    {
        $configDir = $this->dind->registryLogin()->configDir();
        $cacheFrom = (new ProjectBuildCache($this->dind))->cacheFrom($projectDir, BuildCache::RAILPACK);

        return [
            'env', 'PWD='.$projectDir, ...($configDir === null ? [] : ['DOCKER_CONFIG='.$configDir]),
            'docker', 'buildx', 'build',
            '--build-arg', 'BUILDKIT_SYNTAX='.RailpackCache::FRONTEND_IMAGE,
            ...array_merge(...array_map(static fn (string $from): array => ['--cache-from', $from], $cacheFrom)),
            '-f', $planPath,
            '--output', "type=docker,name={$imageName}",
            '.',
        ];
    }

    /** The plan railpack wrote, or null when it cannot be read; the build still runs, without a preload. */
    private function readPlan(string $planPath, string $username): ?string
    {
        try {
            $planJson = $this->dind->system()->filesystem()->fileGetContents($planPath);
        } catch (\Exception $e) {
            Log::info(
                "Could not read the railpack plan for {$username}.",
                ['error' => $e->getMessage()]
            );

            return null;
        }

        return is_string($planJson) && $planJson !== '' ? $planJson : null;
    }

    /** An image with nothing to start runs `bash -c /bin/bash`, which exits at once and restarts forever. */
    private function imageStarts(string $imageName): bool
    {
        try {
            $config = $this->dind->accountDaemon()->image($imageName)['Config'] ?? null;
        } catch (\Exception) {
            return true;
        }

        return ! is_array($config) || ! in_array($config['Cmd'] ?? null, [null, [], [self::IDLE_SHELL]], true);
    }

    /**
     * A template whose package.json only builds or lints ships its pages; served by nginx
     * they answer, where an image with nothing to start cannot. False when there are none.
     */
    private function serveStaticOutput(string $projectDir, ?string $chown): bool
    {
        $logger = $this->dind->shell()->logger();
        $outputDir = $this->staticOutputDir($projectDir);
        if ($outputDir === null) {
            $logger?->warn('Railpack found no start command, and the project has no index.html to serve instead');

            return false;
        }
        $logger?->warn("Railpack found no start command; serving the pages in {$outputDir}/ as a static site");
        $this->dind->freezeDeploySnapshot(['deploy_strategy' => Strategies::STATIC, 'deploy_label' => 'Static']);
        $writer = $this->dind->composeWriter();
        $writer->writeStaticNginxConf($projectDir, null, $chown);
        $writer->writeGeneratedCompose(
            $projectDir,
            DeployCompose::framework(
                ['runtime' => PlatformManifest::RUNTIME_NGINX, 'output_directory' => $outputDir],
                self::DEFAULT_PORT
            ),
            $chown
        );

        return true;
    }

    private function staticOutputDir(string $projectDir): ?string
    {
        $fs = $this->dind->system()->filesystem();
        foreach (self::STATIC_OUTPUT_DIRS as $dir) {
            if ($fs->fileExists("{$projectDir}/{$dir}/index.html")) {
                return $dir;
            }
        }

        return null;
    }

    private function imagePort(string $imageName, string $username): int
    {
        $port = self::DEFAULT_PORT;
        try {
            $exposedPorts = $this->dind->accountDaemon()->image($imageName)['Config']['ExposedPorts'] ?? null;
            if (is_array($exposedPorts) && ! empty($exposedPorts)) {
                $detected = (int) explode('/', (string) array_key_first($exposedPorts))[0];
                if ($detected > 0) {
                    $port = $detected;
                }
            }
        } catch (\Exception $e) {
            Log::info(
                "Could not detect port from railpack image for {$username}, defaulting to {$port}.",
                ['error' => $e->getMessage()]
            );
        }

        return $port;
    }
}
