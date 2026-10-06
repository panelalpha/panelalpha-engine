<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Port\PublishedPort;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Yaml\Yaml;

/**
 * Forward to the port the application actually bound (generated compose only).
 */
final class AppPortAlignment
{
    private const SETTLE_ATTEMPTS = 12;

    private const SETTLE_INTERVAL_SECONDS = 2;

    /** Each probe can take 10s (http, then https) on a socket that never answers. */
    private const MAX_PROBED = 3;

    /**
     * @param (\Closure(int): void)|null $sleep
     */
    public function __construct(
        private DindProject $project,
        private ?\Closure $sleep = null,
    ) {
    }

    public function alignIfNeeded(): void
    {
        if (!$this->realign(null)) {
            return;
        }
        try {
            $this->project->shell()->exec(
                $this->project->userAppComposeCommand(['up', '-d', '--no-build']),
                [],
                300
            );
        } catch (\Exception $e) {
            Log::warning('Could not align the published app port: ' . $e->getMessage());
        }
    }

    /**
     * The same, measured on a redeploy's second copy instead of the running
     * app; the caller starts the copy again. True when the run file changed.
     * The wait ends early once $stopped() says the copy no longer runs.
     *
     * @param (\Closure(): bool)|null $stopped
     */
    public function alignTo(string $container, ?\Closure $stopped = null): bool
    {
        return $this->realign($container, $stopped);
    }

    /** True once the run file forwards to the port $container (default: the app's own) binds. */
    private function realign(?string $container, ?\Closure $stopped = null): bool
    {
        $composePath = $this->project->userAppComposeFilePath();
        if ($this->project->userAppComposeFileToRun() !== $composePath) {
            return false;
        }

        $filesystem = $this->project->system()->filesystem();
        $logger = $this->project->shell()->logger();

        try {
            $raw = $filesystem->fileGetContents($composePath);
            if ($raw === '') {
                return false;
            }
            $parsed = Yaml::parse($raw);
            if (!is_array($parsed) || !isset($parsed['services']['app']['ports'])) {
                return false;
            }
            $ports = $parsed['services']['app']['ports'];
            if (!is_array($ports) || !isset($ports[0]) || !is_string($ports[0])) {
                return false;
            }
            $mapping = PublishedPort::parse($ports[0]);
            if ($mapping === null) {
                return false;
            }

            $candidates = $this->settledCandidates(
                $mapping->container,
                $this->declaredPorts(dirname($composePath), $parsed['services']['app']['build'] ?? null),
                $container,
                $stopped
            );
            if ($candidates === []) {
                return false;
            }
            // A socket is not a website: epmd, php-fpm and SSH bind first and
            // never answer HTTP, and forwarding there leaves the site dead.
            $actual = self::firstAnsweringHttp(
                $candidates,
                fn (int $port): ?bool => self::answersHttp($this->httpStatusOf($port, $container))
            );
            if ($actual === null) {
                $probed = array_slice($candidates, 0, self::MAX_PROBED);
                $listed = implode(', ', $probed);
                $logger?->warn(
                    'Application is listening on port' . (count($probed) > 1 ? 's' : '') . " {$listed}, not {$mapping->container}, but "
                        . (count($probed) > 1 ? 'none of them answers' : "{$listed} does not answer")
                        . " HTTP; still forwarding to {$mapping->container}"
                );

                return false;
            }

            $logger?->info(
                "Application is listening on port {$actual}, not {$mapping->container}; forwarding there instead"
            );
            Telemetry::signal(
                $this->project->userModel()->username,
                'port-realigned',
                "Recipe expected port {$mapping->container}, application bound {$actual}"
            );
            $parsed['services']['app']['ports'][0] = $mapping->forwardingTo($actual);
            $filesystem->filePutContents(
                $composePath,
                ComposeYaml::dump($parsed, $raw, 6, 2),
                $this->project->userModel()->getChownString(),
                EngineArtifacts::RUN_COMPOSE_MODE
            );

            return true;
        } catch (\Exception $e) {
            Log::warning('Could not align the published app port: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param list<int> $declared
     * @return list<int>
     */
    private function settledCandidates(int $expected, array $declared, ?string $container, ?\Closure $stopped): array
    {
        return self::awaitCandidates($expected, fn (): array => $this->listeningSockets($container), $this->sleep, $declared, $stopped);
    }

    /**
     * The first candidate whose probe did not rule HTTP out, trying at most
     * {@see MAX_PROBED}; null when every one tried answered nothing.
     *
     * @param list<int> $candidates best first
     * @param callable(int): ?bool $answersHttp null when the probe could not run
     */
    public static function firstAnsweringHttp(array $candidates, callable $answersHttp): ?int
    {
        foreach (array_slice($candidates, 0, self::MAX_PROBED) as $port) {
            if ($answersHttp($port) !== false) {
                return $port;
            }
        }

        return null;
    }

    /**
     * Whether the probe of a candidate port got an HTTP answer: false for
     * `000` (nothing HTTP answered), null when the probe could not run.
     */
    public static function answersHttp(string $probeOutput): ?bool
    {
        $code = trim($probeOutput);
        if (preg_match('/^\d{3}$/', $code) !== 1) {
            return null;
        }

        return $code !== '000';
    }

    /** The script line that sets `cid`: $container, else the app service's container. */
    private function containerIdLine(?string $container): string
    {
        if ($container !== null) {
            return 'cid=' . escapeshellarg($container);
        }
        $composeFile = escapeshellarg($this->project->userAppComposeFileToRun());
        $projectDir = escapeshellarg($this->project->userAppDirPath());

        return 'cid=$(' . $this->envFilesAssignment() . "docker compose --project-directory {$projectDir} -f {$composeFile} ps -q app 2>/dev/null | head -1)";
    }

    /** `COMPOSE_ENV_FILES=… ` for a script's bare compose call, or '' when compose's default `.env` is all there is. */
    private function envFilesAssignment(): string
    {
        $files = $this->project->userAppComposeEnv()['COMPOSE_ENV_FILES'] ?? null;

        return $files === null ? '' : 'COMPOSE_ENV_FILES=' . escapeshellarg($files) . ' ';
    }

    /** The status the app container answers on $port, over http then https; '' when it cannot be asked. */
    private function httpStatusOf(int $port, ?string $container): string
    {
        $cid = $this->containerIdLine($container);
        $script = <<<SH
{$cid}
[ -n "\$cid" ] || exit 0
ip=\$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}' "\$cid" 2>/dev/null | awk '{print \$1}')
[ -n "\$ip" ] || exit 0
for scheme in http https; do
    code=\$(curl -sk -o /dev/null -w '%{http_code}' --max-time 5 "\$scheme://\$ip:{$port}/" 2>/dev/null)
    [ -n "\$code" ] && [ "\$code" != 000 ] && break
done
echo "\${code:-000}"
SH;

        try {
            return $this->project->shell()->execQuiet(['bash', '-c', $script], [], 30);
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * The TCP ports the built Dockerfile EXPOSEs.
     *
     * @param mixed $build the app service's `build:` value
     * @return list<int>
     */
    private function declaredPorts(string $composeDir, $build): array
    {
        if (is_string($build)) {
            $build = ['context' => $build];
        }
        if (!is_array($build)) {
            return [];
        }
        $context = is_string($build['context'] ?? null) ? $build['context'] : '.';
        $dockerfile = is_string($build['dockerfile'] ?? null) ? $build['dockerfile'] : 'Dockerfile';
        $contents = $this->project->projectTree()->read(
            $composeDir . '/' . trim($context, '/') . '/' . ltrim($dockerfile, '/')
        );

        return $contents === null ? [] : DockerfileFinder::exposedPortsIn($contents);
    }

    /**
     * Wait out the whole window for the expected port; only if it never binds
     * is the last candidate seen the answer. MeTube binds a helper on 4416
     * before its server on 8081, so the first unexpected port is not it.
     *
     * @param callable(): list<array{addr: string, port: int}> $sockets
     * @param (callable(int): void)|null $sleep
     * @param list<int> $declared ports the Dockerfile EXPOSEs
     */
    public static function awaitPort(int $expected, callable $sockets, ?callable $sleep = null, array $declared = []): ?int
    {
        return self::awaitCandidates($expected, $sockets, $sleep, $declared)[0] ?? null;
    }

    /**
     * {@see awaitPort()}, with every candidate of the last poll that saw one,
     * best first; none once $stopped() says the container stopped running.
     *
     * @param callable(): list<array{addr: string, port: int}> $sockets
     * @param (callable(int): void)|null $sleep
     * @param list<int> $declared
     * @param (callable(): bool)|null $stopped
     * @return list<int>
     */
    public static function awaitCandidates(int $expected, callable $sockets, ?callable $sleep = null, array $declared = [], ?callable $stopped = null): array
    {
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        $candidates = [];
        for ($attempt = 0; $attempt < self::SETTLE_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                $sleep(self::SETTLE_INTERVAL_SECONDS);
            }
            $seen = $sockets();
            if (DetectAppPort::servesPort($seen, $expected)) {
                return [];
            }
            // A crash-looping container binds nothing worth the rest of the window.
            if ($stopped !== null && $stopped()) {
                return [];
            }
            $ranked = DetectAppPort::rankedAppPorts($seen, $expected, $declared);
            $candidates = $ranked !== [] ? $ranked : $candidates;
        }

        return $candidates;
    }

    /**
     * @return list<array{addr: string, port: int}>
     */
    private function listeningSockets(?string $container): array
    {
        $cid = $this->containerIdLine($container);
        $script = <<<SH
{$cid}
[ -n "\$cid" ] || exit 0
state=\$(docker inspect -f '{{.State.Status}}' "\$cid" 2>/dev/null)
[ "\$state" = "running" ] || exit 0
pid=\$(docker inspect -f '{{.State.Pid}}' "\$cid" 2>/dev/null)
[ -n "\$pid" ] && [ "\$pid" != "0" ] || exit 0
cat /proc/\$pid/net/tcp /proc/\$pid/net/tcp6 2>/dev/null
SH;

        try {
            return DetectAppPort::listeningSocketsFromProcNet(
                $this->project->shell()->execQuiet(['bash', '-c', $script], [], 60)
            );
        } catch (\Exception $e) {
            return [];
        }
    }
}
