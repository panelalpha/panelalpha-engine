<?php

namespace App\System\Project\Dind;

use App\Lib\Deploy\Compose\ComposeYaml;
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

    public function __construct(
        private DindProject $project,
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

            $actual = $this->settledPort($mapping->container);
            if ($actual === null) {
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
                '644'
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

    private function settledPort(int $expected): ?int
    {
        return self::awaitPort($expected, fn (): array => $this->listeningSockets());
    }

    /**
     * Wait out the whole window for the expected port; only if it never binds
     * is the last candidate seen the answer. MeTube binds a helper on 4416
     * before its server on 8081, so the first unexpected port is not it.
     *
     * @param callable(): list<array{addr: string, port: int}> $sockets
     * @param (callable(int): void)|null $sleep
     */
    public static function awaitPort(int $expected, callable $sockets, ?callable $sleep = null): ?int
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
            $candidate = DetectAppPort::chooseAppPort($seen, $expected) ?? $candidate;
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
