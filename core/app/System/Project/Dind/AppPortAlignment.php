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
        $composePath = $this->project->userAppComposeFilePath();
        if ($this->project->userAppComposeFileToRun() !== $composePath) {
            return;
        }

        $filesystem = $this->project->system()->filesystem();
        $logger = $this->project->shell()->logger();

        try {
            $raw = $filesystem->fileGetContents($composePath);
            if ($raw === '') {
                return;
            }
            $parsed = Yaml::parse($raw);
            if (!is_array($parsed) || !isset($parsed['services']['app']['ports'])) {
                return;
            }
            $ports = $parsed['services']['app']['ports'];
            if (!is_array($ports) || !isset($ports[0]) || !is_string($ports[0])) {
                return;
            }
            $mapping = PublishedPort::parse($ports[0]);
            if ($mapping === null) {
                return;
            }

            $actual = $this->settledPort(
                $mapping->container,
                $this->declaredPorts(dirname($composePath), $parsed['services']['app']['build'] ?? null)
            );
            if ($actual === null) {
                return;
            }
            // A socket is not a website: epmd, php-fpm and SSH bind first and
            // never answer HTTP, and forwarding there leaves the site dead.
            if (self::answersHttp($this->httpStatusOf($actual)) === false) {
                $logger?->warn(
                    "Application is listening on port {$actual}, not {$mapping->container}, but {$actual} "
                        . "does not answer HTTP; still forwarding to {$mapping->container}"
                );

                return;
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
     * @param list<int> $declared
     */
    private function settledPort(int $expected, array $declared): ?int
    {
        return self::awaitPort($expected, fn (): array => $this->listeningSockets(), $this->sleep, $declared);
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

    /** The status the app container answers on $port, over http then https; '' when it cannot be asked. */
    private function httpStatusOf(int $port): string
    {
        $composeFile = escapeshellarg($this->project->userAppComposeFileToRun());
        $projectDir = escapeshellarg($this->project->userAppDirPath());
        $script = <<<SH
cid=\$(docker compose --project-directory {$projectDir} -f {$composeFile} ps -q app 2>/dev/null | head -1)
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
        $sleep ??= static fn (int $seconds) => sleep($seconds);
        $candidate = null;
        for ($attempt = 0; $attempt < self::SETTLE_ATTEMPTS; $attempt++) {
            if ($attempt > 0) {
                $sleep(self::SETTLE_INTERVAL_SECONDS);
            }
            $seen = $sockets();
            if (DetectAppPort::servesPort($seen, $expected)) {
                return null;
            }
            $candidate = DetectAppPort::chooseAppPort($seen, $expected, $declared) ?? $candidate;
        }

        return $candidate;
    }

    /**
     * @return list<array{addr: string, port: int}>
     */
    private function listeningSockets(): array
    {
        $composeFile = escapeshellarg($this->project->userAppComposeFileToRun());
        $projectDir = escapeshellarg($this->project->userAppDirPath());
        $script = <<<SH
cid=\$(docker compose --project-directory {$projectDir} -f {$composeFile} ps -q app 2>/dev/null | head -1)
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
