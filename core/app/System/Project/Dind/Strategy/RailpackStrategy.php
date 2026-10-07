<?php

namespace App\System\Project\Dind\Strategy;

use App\System\Project\Dind as DindProject;
use App\Lib\Deploy\CacheManager\RailpackCache;
use App\Lib\Deploy\Compose\DeployCompose;
use App\Lib\Deploy\Platform\AppConfig\AppConfigDirectory;
use App\Lib\Deploy\Platform\Strategies;
use Illuminate\Support\Facades\Log;

/**
 * The last two things to try: build the project with Railpack, or serve a
 * page saying nothing could be built.
 *
 * Railpack works out how to build a project nobody wrote a recipe for. It
 * runs as `railpack prepare` plus `docker buildx build` against the railpack
 * frontend, which uses Docker's own BuildKit — no separate daemon container.
 * Every failure returns false rather than throwing, because the fallback
 * compose is always available and a page is a better answer than an error.
 *
 * The image is named per account. Each account has an isolated inner Docker
 * daemon (sysbox), so there is no cross-account name collision.
 */
class RailpackStrategy
{
    private const BUILD_TIMEOUT_SECONDS = 3600;

    private const PREPARE_TIMEOUT_SECONDS = 900;

    /** Kill inside the container this many seconds before the host gives up. */
    private const INNER_TIMEOUT_MARGIN = 60;

    private const DEFAULT_PORT = 80;

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

        $this->dind->shell()->logger()?->info(
            'No compose file and automatic build unavailable, generating basic compose'
        );
        $this->dind->freezeDeploySnapshot([
            'deploy_strategy' => Strategies::FALLBACK,
            'deploy_label' => 'Unknown',
        ]);
        $this->applyFallback($projectDir, $chown, $sourceLabel);
    }

    public function applyFallback(string $projectDir, ?string $chown, string $sourceLabel): void
    {
        // No entry to name: the page written below is index.html, which is
        // what nginx looks for on its own. The config is still written,
        // because the compose file mounts it either way.
        $this->dind->composeWriter()->writeStaticNginxConf($projectDir, null, $chown);
        $this->dind->composeWriter()->writeGeneratedCompose($projectDir, DeployCompose::staticNginx(), $chown);
        $this->dind->composeWriter()->writeFallbackIndexHtml($projectDir, $sourceLabel, $chown);
    }

    /**
     * Build the user's app with Railpack and run the resulting image.
     *
     * On success a compose file that references the image (no bind-mount) is
     * written to $projectDir and true is returned. On any failure false is
     * returned so the caller can fall back to the basic nginx compose.
     */
    /**
     * A directory in the account's home that the account can write to.
     *
     * Created at account setup, but re-asserted here rather than assumed: an
     * account made before it existed, or one whose home was restored from a
     * backup, would otherwise fail exactly the way the home itself does, and
     * a railpack build is not the place to discover it. Falls back to the
     * home when it cannot be made, which is no worse than the old behaviour.
     */
    private function accountScratchDir(?string $chown): string
    {
        $home = rtrim($this->dind->homeDirPath(), '/');
        $scratch = $home . '/' . AppConfigDirectory::DIRNAME;
        $system = $this->dind->system();

        if ($system->directoryExists($scratch)) {
            return $scratch;
        }

        try {
            $system->exec(['sudo', 'mkdir', '-p', $scratch], [], 30);
            if (is_string($chown) && $chown !== '') {
                $system->exec(['sudo', 'chown', $chown, $scratch], [], 30);
            }
        } catch (\Throwable $e) {
            Log::info('Could not prepare the railpack scratch directory', ['error' => $e->getMessage()]);

            return $home;
        }

        return $system->directoryExists($scratch) ? $scratch : $home;
    }

    private function tryBuild(string $projectDir, ?string $chown): bool
    {
        $username = $this->dind->userModel()->username;
        $system = $this->dind->system();
        $shell = $this->dind->shell();
        $imageName = "panelalpha-{$username}-app:latest";
        $logger = $shell->logger();

        try {
            $shell->execAsUser(['which', 'railpack']);
        } catch (\Exception $e) {
            Log::info("Railpack not available in dind container for {$username}, skipping railpack build.");
            $logger?->dim('Railpack not available, skipping automatic build');

            return false;
        }

        $logger?->info('Building application image (railpack + buildx)');

        $innerTimeout = max(60, self::BUILD_TIMEOUT_SECONDS - self::INNER_TIMEOUT_MARGIN);
        // In the account's home rather than /tmp, because the engine rewrites
        // the plan between `railpack prepare` and the build and only the home
        // directory is reachable from the host side -- but in ~/.panelalpha
        // rather than the home itself, which is root:root 755.
        //
        // `railpack prepare --plan-out` runs *as the account* and so could not
        // create a file there:
        //
        //     ERRO open /home/strapi/railpack-plan-tmp.json: permission denied
        //     No compose file and automatic build unavailable, generating basic compose
        //
        // and the deploy then fell through to the nginx placeholder, reporting
        // "no recipe recognised it" for a project whose recipe had matched and
        // failed. The home is root-owned for SFTP chrooting, which is not
        // negotiable; ~/.panelalpha is created beside it at account setup,
        // belongs to the account, and is already excluded from the build
        // context. Nothing else about the arrangement changes -- still in the
        // home, still reachable from the host.
        $scratch = $this->accountScratchDir($chown);
        $planPath = $scratch . '/railpack-plan-tmp.json';
        $buildScriptPath = $scratch . '/railpack-build-tmp.sh';

        $prepareScript =
            '#!/bin/bash' . "\n" .
            'set -e' . "\n" .
            'cd ' . escapeshellarg($projectDir) . "\n" .
            'railpack prepare . --plan-out ' . escapeshellarg($planPath) . "\n";

        try {
            $system->filesystem()->filePutContents($buildScriptPath, $prepareScript, $chown, '700');
            $shell->execAsUser(['bash', $buildScriptPath], [], self::PREPARE_TIMEOUT_SECONDS);
            $planJson = $this->readPlan($planPath, $username);

            // The preload waits for the plan rather than running before
            // `railpack prepare`: the builder and runtime tags follow
            // Railpack's own release calendar and the plan is the only thing
            // that knows which it resolved to -- a list kept beside this
            // loaded two tags the build then pulled itself.
            $this->dind->innerDocker()->preloadRailpackBaseImages(
                RailpackCache::preloadImages((string) $planJson)
            );
            $buildScript =
                '#!/bin/bash' . "\n" .
                'set -e' . "\n" .
                "cleanup() { rm -f " . escapeshellarg($planPath) . "; }" . "\n" .
                'trap cleanup EXIT' . "\n" .
                self::dockerConfigLine($this->dind->registryLogin()->configDir()) .
                'cd ' . escapeshellarg($projectDir) . "\n" .
                "timeout --foreground --kill-after=30 {$innerTimeout}" .
                ' docker buildx build' .
                ' --build-arg BUILDKIT_SYNTAX="' . RailpackCache::FRONTEND_IMAGE . '"' .
                ' -f ' . escapeshellarg($planPath) .
                ' --output ' . escapeshellarg("type=docker,name={$imageName}") .
                ' . ';

            $system->filesystem()->filePutContents($buildScriptPath, $buildScript, $chown, '700');
            $shell->execAsUser(['bash', $buildScriptPath], [], self::BUILD_TIMEOUT_SECONDS);
        } catch (\Exception $e) {
            Log::info(
                "Railpack build failed for {$username} (unsupported project or build error), falling back to nginx compose.",
                ['error' => $e->getMessage()]
            );

            return false;
        } finally {
            // The build script's trap removes the plan on a normal run; this
            // covers the paths that never reach it (prepare failed, rewrite
            // threw), so no plan is left in the customer's home directory.
            $system->exec(['sudo', 'rm', '-f', $buildScriptPath, $planPath]);
        }

        $port = $this->imagePort($imageName, $username);
        $this->dind->composeWriter()->writeGeneratedCompose(
            $projectDir,
            DeployCompose::railpack($imageName, $port),
            $chown
        );

        Log::info("Railpack build succeeded for {$username}; image={$imageName}, port={$port}.");
        $logger?->ok('Application image built');

        return true;
    }

    /**
     * Cache tags worth reading for this plan, or null to read them all.
     *
     * Anything unexpected — unreadable plan, a runtime we do not warm — returns
     * null so the build keeps the old behaviour. Guessing a narrower list wrong
     * costs a full rebuild; a spare round trip costs milliseconds.
     *
     * @return list<string>|null
     */
    /**
     * The plan railpack wrote, or null when it cannot be read — the build
     * still runs, it just gets every cache tag and no preload.
     */
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



    private function imagePort(string $imageName, string $username): int
    {
        $port = self::DEFAULT_PORT;
        try {
            $inspectJson = $this->dind->shell()->execAsUser(
                ['docker', 'image', 'inspect', $imageName, '--format', '{{json .Config.ExposedPorts}}']
            );
            $exposedPorts = json_decode(trim($inspectJson), true);
            if (is_array($exposedPorts) && !empty($exposedPorts)) {
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

    /** The build pulls the plan's images itself; with logins held, it reads them from there. */
    public static function dockerConfigLine(?string $configDir): string
    {
        return $configDir === null ? '' : 'export DOCKER_CONFIG=' . escapeshellarg($configDir) . "\n";
    }
}
