<?php

namespace App\System\Privileged;

/** Docker commands as root. */
final class RootDocker
{
    private const AREA = 'docker';

    private const RUNS = ['nodeBuild', 'phpBuild', 'composerInstall', 'readFileFromImage', 'checkLiteSpeedSerial'];

    /** @return list<string> */
    public function info(): array
    {
        return $this->docker(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function registryMirrors(): array
    {
        return $this->docker(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function containerImages(): array
    {
        return $this->docker(__FUNCTION__, []);
    }

    /**
     * Exactly $name, in any state.
     *
     * @return list<string>
     */
    public function containerNamed(string $name): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($name)]);
    }

    /**
     * Exactly $name, only when its $label is $value.
     *
     * @return list<string>
     */
    public function containerNamedWithLabel(string $name, string $label, string $value): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($name), Arguments::name($label), Arguments::operand($value)]);
    }

    /** @return list<string> */
    public function runningContainerIdsLabelled(string $label): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($label)]);
    }

    /** @return list<string> */
    public function runningContainerIdsWithLabel(string $label, string $value): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($label), Arguments::operand($value)]);
    }

    /** @return list<string> */
    public function stoppedContainerIdsLabelled(string $label): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($label)]);
    }

    /**
     * Name, state and $label's value, tab-separated.
     *
     * @return list<string>
     */
    public function containersLabelled(string $label): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($label)]);
    }

    /**
     * Name, runtime, working directory, start time and last start's error of each, tab-separated.
     *
     * @return list<string>
     */
    public function inspect(string ...$containers): array
    {
        return $this->docker(__FUNCTION__, $this->names(array_values($containers)));
    }

    /** @return list<string> */
    public function isRunning(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    public function startedAt(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /**
     * The container's CPU limit in billionths of a CPU, 0 for none.
     *
     * @return list<string>
     */
    public function nanoCpus(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    public function containerNetworks(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /**
     * One `{{.CPUPerc}}` line per container.
     *
     * @return list<string>
     */
    public function cpuPercents(string ...$containers): array
    {
        return $this->docker(__FUNCTION__, $this->names(array_values($containers)));
    }

    /**
     * One variable per line, nothing when the container is not running.
     *
     * @return list<string>
     */
    public function runningEnvironment(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    public function limitMemory(string $container, int $megabytes): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container), HostValues::positive($megabytes, 'memory')]);
    }

    /** @return list<string> */
    public function limitCpu(string $container, int|float $cpus): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container), HostValues::cpus($cpus)]);
    }

    /** @return list<string> */
    public function restart(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    public function forceRemoveContainer(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    public function forceRemoveContainers(string ...$containers): array
    {
        return $this->docker(__FUNCTION__, $this->names(array_values($containers)));
    }

    /** @param  list<string>  $argv */
    public function isRun(array $argv): bool
    {
        return in_array(Root::askedAction($argv), $this->runActions(), true);
    }

    /**
     * Adds `--label $label=$value` before the run's other options.
     *
     * @param  list<string>  $argv
     * @return list<string>
     */
    public function withRunLabel(array $argv, string $label, string $value): array
    {
        if (! $this->isRun($argv)) {
            throw InvalidArgument::of('docker run', implode(' ', $argv));
        }

        return Root::reasked($argv, ActionValues::withPairInFront(Root::askedValues($argv), 0, [Arguments::name($label), Arguments::operand($value)]));
    }

    /**
     * One `<subnet> <gateway>` line per IPv4 subnet.
     *
     * @return list<string>
     */
    public function networkSubnetsAndGateways(string $network): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($network)]);
    }

    /** @return list<string> */
    public function networkId(string $network): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($network)]);
    }

    /** @return list<string> */
    public function createBridgeNetwork(string $network, string $bridge, string $role): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($network), Arguments::name($bridge), Arguments::name($role)]);
    }

    /** @return list<string> */
    public function connectNetwork(string $network, string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($network), Arguments::name($container)]);
    }

    /** @return list<string> */
    public function disconnectNetwork(string $network, string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($network), Arguments::name($container)]);
    }

    /** @return list<string> */
    public function pull(string $image): array
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    /** @return list<string> */
    public function inspectManifest(string $image): array
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    /** @return list<string> */
    public function inspectImage(string $image): array
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    /** @return list<string> */
    public function imageId(string $image): array
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    /** @return list<string> */
    public function imageExposedPorts(string $image): array
    {
        return $this->docker(__FUNCTION__, [Arguments::image($image)]);
    }

    /** @return list<string> */
    public function imagesLastTagTime(string ...$images): array
    {
        return $this->docker(__FUNCTION__, Arguments::images(array_values($images)));
    }

    /** @return list<string> */
    public function imageNames(): array
    {
        return $this->docker(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function imageNamesWithSizes(): array
    {
        return $this->docker(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function removeImages(string ...$images): array
    {
        return $this->docker(__FUNCTION__, Arguments::images(array_values($images)));
    }

    /** @return list<string> */
    public function forceRemoveImages(string ...$images): array
    {
        return $this->docker(__FUNCTION__, Arguments::images(array_values($images)));
    }

    /**
     * `docker build -` with the Dockerfile on stdin, no provenance or SBOM.
     *
     * @param  array<string, string>  $buildArgs
     * @return list<string>
     */
    public function buildFromStdin(string $tag, array $buildArgs, bool $noCache): array
    {
        return $this->docker(__FUNCTION__, [Arguments::image($tag), $noCache ? 'no-cache' : '', ...$this->environment($buildArgs)]);
    }

    /** @return list<string> */
    public function pruneBuildCache(): array
    {
        return $this->docker(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function pruneBuildCacheUnusedFor(int $seconds): array
    {
        return $this->docker(__FUNCTION__, [HostValues::count($seconds, 'seconds')]);
    }

    /** @return list<string> */
    public function pruneBuildCacheAbove(int $bytes): array
    {
        return $this->docker(__FUNCTION__, [HostValues::count($bytes, 'bytes')]);
    }

    /** @return list<string> */
    public function pruneBuildCacheUntilFree(int $bytes): array
    {
        return $this->docker(__FUNCTION__, [HostValues::count($bytes, 'bytes')]);
    }

    /**
     * $script under `sh -c`, behind the image's own entrypoint.
     *
     * @return list<string>
     */
    public function nodeBuild(BuildContainer $container, string $script): array
    {
        return $this->hostBuild(__FUNCTION__, $container, $script);
    }

    /**
     * Runs in the image the app is served from, with `--entrypoint sh` to skip the image's shim.
     *
     * @return list<string>
     */
    public function phpBuild(BuildContainer $container, string $script): array
    {
        return $this->hostBuild(__FUNCTION__, $container, $script);
    }

    /** @return list<string> */
    public function composerInstall(BuildContainer $container, string $script): array
    {
        return $this->hostBuild(__FUNCTION__, $container, $script);
    }

    /**
     * Reads only the host's copy of $image.
     *
     * @return list<string>
     */
    public function readFileFromImage(string $image, string $path): array
    {
        return $this->docker(__FUNCTION__, ['0', Arguments::image($image), Arguments::path($path)]);
    }

    /** @return list<string> */
    public function checkLiteSpeedSerial(string $serialFile): array
    {
        return $this->docker(__FUNCTION__, ['0', Arguments::mountPath($serialFile)]);
    }

    /** @return list<string> */
    public function registryGarbageCollect(string $container, string $config): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container), Arguments::path($config)]);
    }

    /**
     * `<mtime> <digest> <path>` per tag link.
     *
     * @return list<string>
     */
    public function registryTagLinks(string $container, string $repositories): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container), Arguments::path($repositories)]);
    }

    /** @return list<string> */
    public function registryStorageKilobytes(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    public function removeRegistryRepository(string $container, string $repositories, string $repository): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container), Arguments::path($repositories), Arguments::registryRepository($repository)]);
    }

    /** @return list<string> */
    public function wipeRegistryStorage(string $container): array
    {
        return $this->docker(__FUNCTION__, [Arguments::name($container)]);
    }

    /** @return list<string> */
    private function hostBuild(string $action, BuildContainer $container, string $script): array
    {
        return $this->docker($action, ['0', ...$this->buildValues($container), $script]);
    }

    /**
     * In the order the broker reads them. A null network is sent as ''.
     *
     * @return list<string>
     */
    private function buildValues(BuildContainer $container): array
    {
        return [
            Arguments::owner($container->user),
            Arguments::operand($container->memory),
            $container->network === null ? '' : Arguments::name($container->network),
            ...$this->mounts($container->mounts),
            ...$this->mounts($container->readOnlyMounts),
            Arguments::path($container->workdir),
            ...$this->environment($container->environment),
            Arguments::image($container->image),
        ];
    }

    /**
     * @param  array<string, string>  $mounts  container path => host path
     * @return list<string>
     */
    private function mounts(array $mounts): array
    {
        foreach ($mounts as $target => $source) {
            Arguments::mountPath($source);
            Arguments::mountPath((string) $target);
        }

        return ActionValues::pairs($mounts);
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private function environment(array $environment): array
    {
        foreach (array_keys($environment) as $name) {
            Arguments::environmentName((string) $name);
        }

        return ActionValues::pairs($environment);
    }

    /**
     * @param  array<int, string>  $containers
     * @return list<string>
     */
    private function names(array $containers): array
    {
        return array_map(Arguments::name(...), array_values($containers));
    }

    /** @return list<string> */
    private function runActions(): array
    {
        return array_map(static fn (string $action): string => self::AREA.'.'.$action, self::RUNS);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function docker(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values);
    }
}
