<?php

namespace App\System\Project\Dind;

use App\Exceptions\DeployCancelledException;
use App\Lib\Deploy\Checkout\EngineArtifacts;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\DeployLog\DeployLogger;
use App\Lib\Deploy\Detect\DockerfileFinder;
use App\Lib\Deploy\DetectAppPort;
use App\Lib\Deploy\Port\PublishedPort;
use App\Lib\Deploy\Telemetry\Telemetry;
use App\System\Filesystem;
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

    private const PROBE_TIMEOUT_SECONDS = 30;

    private const SOCKETS_TIMEOUT_SECONDS = 60;

    /** Run in the account: $1 the container's address, $2 the port; prints its status over http, else https, else 000. */
    private const HTTP_STATUS = 'for scheme in http https; do '
        .'code=$(curl -sk -o /dev/null -w \'%{http_code}\' --max-time 5 "$scheme://$1:$2/" 2>/dev/null); '
        .'[ -n "$code" ] && [ "$code" != 000 ] && break; '
        .'done; echo "${code:-000}"';

    /**
     * @param  (\Closure(int): void)|null  $sleep
     */
    public function __construct(
        private DindProject $project,
        private ?\Closure $sleep = null,
    ) {}

    public function alignIfNeeded(): void
    {
        if (! $this->realign(null)) {
            return;
        }
        try {
            $this->project->shell()->exec(
                $this->project->userAppComposeCommand(['up', '-d', '--no-build']),
                [],
                300
            );
        } catch (\Exception $e) {
            Log::warning('Could not align the published app port: '.$e->getMessage());
        }
    }

    /**
     * The same, read from a redeploy's second copy, which the caller starts again; true when the run file
     * changed. The wait ends early once $stopped() says the copy has stopped.
     *
     * @param  (\Closure(): bool)|null  $stopped
     *
     * @throws DeployCancelledException
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
            return $this->realignRunFile($composePath, $filesystem, $logger, $container, $stopped);
        } catch (DeployCancelledException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::warning('Could not align the published app port: '.$e->getMessage());

            return false;
        }
    }

    /** @throws DeployCancelledException */
    private function realignRunFile(
        string $composePath,
        Filesystem $filesystem,
        ?DeployLogger $logger,
        ?string $container,
        ?\Closure $stopped
    ): bool {
        $filesystem->assertNotSymlink($composePath);
        $raw = $filesystem->fileGetContents($composePath);
        if ($raw === '') {
            return false;
        }
        $parsed = Yaml::parse($raw);
        $mapping = self::firstPortMapping($parsed);
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
        $actual = $this->firstCandidateServingHttp($candidates, $container);
        if ($actual === null) {
            self::warnNoCandidateAnswers($logger, $candidates, $mapping);

            return false;
        }

        $this->forwardTo($actual, $mapping, $composePath, $raw, $parsed, $filesystem, $logger);

        return true;
    }

    /** The app service's first published port, when it is the short form PublishedPort reads. */
    private static function firstPortMapping(mixed $parsed): ?PublishedPort
    {
        if (! is_array($parsed) || ! isset($parsed['services']['app']['ports'])) {
            return null;
        }
        $ports = $parsed['services']['app']['ports'];
        if (! is_array($ports) || ! isset($ports[0]) || ! is_string($ports[0])) {
            return null;
        }

        return PublishedPort::parse($ports[0]);
    }

    /**
     * A socket is not a website: epmd, php-fpm and SSH bind first and never answer HTTP, and forwarding
     * there leaves the site dead.
     *
     * @param  list<int>  $candidates
     */
    private function firstCandidateServingHttp(array $candidates, ?string $container): ?int
    {
        return self::firstAnsweringHttp(
            $candidates,
            fn (int $port): ?bool => self::answersHttp($this->httpStatusOf($port, $container))
        );
    }

    /** @param  list<int>  $candidates */
    private static function warnNoCandidateAnswers(?DeployLogger $logger, array $candidates, PublishedPort $mapping): void
    {
        $probed = array_slice($candidates, 0, self::MAX_PROBED);
        $listed = implode(', ', $probed);
        $logger?->warn(
            'Application is listening on port'.(count($probed) > 1 ? 's' : '')." {$listed}, not {$mapping->container}, but "
                .(count($probed) > 1 ? 'none of them answers' : "{$listed} does not answer")
                ." HTTP; still forwarding to {$mapping->container}"
        );
    }

    /** @param  array<string, mixed>  $parsed */
    private function forwardTo(
        int $actual,
        PublishedPort $mapping,
        string $composePath,
        string $raw,
        array $parsed,
        Filesystem $filesystem,
        ?DeployLogger $logger
    ): void {
        $logger?->info(
            "Application is listening on port {$actual}, not {$mapping->container}; forwarding there instead"
        );
        Telemetry::signal(
            $this->project->userModel()->username,
            'port-realigned',
            "Recipe expected port {$mapping->container}, application bound {$actual}"
        );
        $parsed['services']['app']['ports'][0] = $mapping->forwardingTo($actual);
        $filesystem->writeFileReplacingPath(
            $composePath,
            ComposeYaml::dump($parsed, $raw, 6, 2),
            $this->project->userModel()->getChownString(),
            EngineArtifacts::RUN_COMPOSE_MODE
        );
    }

    /**
     * The wait ends at once on the deploy's cancel, which then stops the deploy.
     *
     * @param  list<int>  $declared
     * @return list<int>
     *
     * @throws DeployCancelledException
     */
    private function settledCandidates(int $expected, array $declared, ?string $container, ?\Closure $stopped): array
    {
        $logger = $this->project->shell()->logger();
        $ended = static fn (): bool => $logger?->isCancelled() === true || ($stopped !== null && $stopped());
        $candidates = self::awaitCandidates($expected, fn (): array => $this->listeningSockets($container), $this->sleep, $declared, $ended);
        $logger?->throwIfCancelled();

        return $candidates;
    }

    /**
     * The first candidate whose probe did not rule HTTP out, trying at most {@see MAX_PROBED}; null when
     * every one tried answered nothing.
     *
     * @param  list<int>  $candidates  best first
     * @param  callable(int): ?bool  $answersHttp  null when the probe could not run
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
     * Whether the probe of a candidate port got an HTTP answer: false for `000` (nothing HTTP answered),
     * null when the probe could not run.
     */
    public static function answersHttp(string $probeOutput): ?bool
    {
        $code = trim($probeOutput);
        if (preg_match('/^\d{3}$/', $code) !== 1) {
            return null;
        }

        return $code !== '000';
    }

    /** $container, else the app service's first running container; null when it has none. */
    private function containerOf(?string $container, int $timeout): ?string
    {
        if ($container !== null) {
            return $container;
        }

        return $this->project->accountDaemon()->stackContainerIds(service: 'app', timeout: $timeout)[0] ?? null;
    }

    /** The status the app container answers on $port, over http then https; '' when it cannot be asked. */
    private function httpStatusOf(int $port, ?string $container): string
    {
        try {
            $cid = $this->containerOf($container, self::PROBE_TIMEOUT_SECONDS);
            $ip = $cid === null ? null : self::firstAddress($this->project->accountDaemon()->container($cid, self::PROBE_TIMEOUT_SECONDS));

            return $ip === null ? '' : $this->project->shell()->execQuiet(
                ['bash', '-c', self::HTTP_STATUS, 'probe', $ip, (string) $port],
                [],
                self::PROBE_TIMEOUT_SECONDS
            );
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * The first address of the container's networks, in the order the daemon names them.
     *
     * @param  array<string, mixed>|null  $inspected
     */
    private static function firstAddress(?array $inspected): ?string
    {
        $networks = $inspected['NetworkSettings']['Networks'] ?? null;
        foreach (is_array($networks) ? $networks : [] as $network) {
            $ip = is_array($network) ? trim((string) ($network['IPAddress'] ?? '')) : '';
            if ($ip !== '') {
                return $ip;
            }
        }

        return null;
    }

    /**
     * The TCP ports the built Dockerfile EXPOSEs.
     *
     * @param  mixed  $build  the app service's `build:` value
     * @return list<int>
     */
    private function declaredPorts(string $composeDir, $build): array
    {
        if (is_string($build)) {
            $build = ['context' => $build];
        }
        if (! is_array($build)) {
            return [];
        }
        $context = is_string($build['context'] ?? null) ? $build['context'] : '.';
        $dockerfile = is_string($build['dockerfile'] ?? null) ? $build['dockerfile'] : 'Dockerfile';
        $contents = $this->project->projectTree()->read(
            $composeDir.'/'.trim($context, '/').'/'.ltrim($dockerfile, '/')
        );

        return $contents === null ? [] : DockerfileFinder::exposedPortsIn($contents);
    }

    /**
     * Wait out the whole window for the expected port; only if it never binds is the last candidate seen the
     * answer, since MeTube binds a helper on 4416 before its server on 8081.
     *
     * @param  callable(): list<array{addr: string, port: int}>  $sockets
     * @param  (callable(int): void)|null  $sleep
     * @param  list<int>  $declared  ports the Dockerfile EXPOSEs
     */
    public static function awaitPort(int $expected, callable $sockets, ?callable $sleep = null, array $declared = []): ?int
    {
        return self::awaitCandidates($expected, $sockets, $sleep, $declared)[0] ?? null;
    }

    /**
     * {@see awaitPort()}, with every candidate of the last poll that saw one, best first; none once $stopped()
     * says the container stopped: a crash-looping one binds nothing worth the rest of the window.
     *
     * @param  callable(): list<array{addr: string, port: int}>  $sockets
     * @param  (callable(int): void)|null  $sleep
     * @param  list<int>  $declared
     * @param  (callable(): bool)|null  $stopped
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
        try {
            $cid = $this->containerOf($container, self::SOCKETS_TIMEOUT_SECONDS);

            return $cid === null ? [] : DetectAppPort::listeningSocketsFromProcNet(
                $this->project->accountDaemon()->listeningSockets($cid, self::SOCKETS_TIMEOUT_SECONDS)
            );
        } catch (\Exception $e) {
            return [];
        }
    }
}
