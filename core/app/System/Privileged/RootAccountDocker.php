<?php

namespace App\System\Privileged;

/**
 * An account's own dockerd through the Engine API. The broker finds the daemon from the account's name and answers
 * with JSON it wrote from checked fields of the daemon's reply.
 */
final class RootAccountDocker
{
    private const AREA = 'account-docker';

    private const FILTERS = ['label', 'status', 'publish'];

    public function __construct(private readonly string $username) {}

    /** @return list<string> */
    public function ping(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /**
     * A JSON list with each container's inspect, or null for one the daemon does not have.
     *
     * @return list<string>
     */
    public function containers(string ...$containers): array
    {
        return $this->ask(__FUNCTION__, array_map(Arguments::name(...), array_values($containers)));
    }

    /**
     * A JSON list of `docker ps` rows. $filters maps a filter of {@see FILTERS} to its values.
     *
     * @param  array<string, list<string>>  $filters
     * @return list<string>
     */
    public function listContainers(bool $all, array $filters): array
    {
        $pairs = [];
        foreach ($filters as $name => $values) {
            if (! in_array($name, self::FILTERS, true)) {
                throw InvalidArgument::of('container filter', $name);
            }
            foreach ($values as $value) {
                array_push($pairs, $name, Arguments::operand($value));
            }
        }

        return $this->ask(__FUNCTION__, [$all ? 'all' : '', (string) intdiv(count($pairs), 2), ...$pairs]);
    }

    /**
     * A JSON list with each image's inspect, or null for one the daemon does not have.
     *
     * @return list<string>
     */
    public function images(string ...$images): array
    {
        return $this->ask(__FUNCTION__, Arguments::images(array_values($images)));
    }

    /**
     * Succeeds when the daemon holds $image with its config: a tag held without one counts as missing.
     *
     * @return list<string>
     */
    public function hasImage(string $image): array
    {
        return $this->ask(__FUNCTION__, [Arguments::image($image)]);
    }

    /**
     * A JSON list of the images with their tags and digests.
     *
     * @return list<string>
     */
    public function listImages(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function tag(string $source, string $target): array
    {
        return $this->ask(__FUNCTION__, [Arguments::image($source), Arguments::image($target)]);
    }

    /**
     * Tries every image; with $force a missing one is no failure.
     *
     * @return list<string>
     */
    public function removeImages(bool $force, string ...$images): array
    {
        return $this->ask(__FUNCTION__, [$force ? 'force' : '', ...Arguments::images(array_values($images))]);
    }

    /**
     * With $loginDirectory, the project's registry login there goes with the pull.
     *
     * @return list<string>
     */
    public function pull(string $image, ?string $loginDirectory = null): array
    {
        return $this->ask(__FUNCTION__, [Arguments::image($image), $loginDirectory === null ? '' : Arguments::path($loginDirectory)]);
    }

    /**
     * The last $lines lines of both streams, merged in the order the container wrote them; with $timestamps each line
     * led by the time the daemon logged it, as `docker logs -t` prints it.
     *
     * @return list<string>
     */
    public function logTail(string $container, int $lines, bool $timestamps = false): array
    {
        return $this->ask(__FUNCTION__, [
            Arguments::name($container),
            HostValues::positive($lines, 'log lines'),
            $timestamps ? 'timestamps' : '',
        ]);
    }

    /**
     * $argv in $container, as $user in $workdir when given; the exit code is the command's.
     *
     * @param  array<string, string>  $environment
     * @param  list<string>  $argv
     * @return list<string>
     */
    public function exec(string $container, array $argv, string $user = '', string $workdir = '', array $environment = []): array
    {
        return $this->ask(__FUNCTION__, [
            Arguments::name($container),
            $user === '' ? '' : Arguments::owner($user),
            $workdir === '' ? '' : Arguments::path($workdir),
            ...self::pairs($environment),
            ...self::command($argv),
        ]);
    }

    /**
     * $argv in a new container of $image, removed once it exits; each mount is [named volume or path in the home,
     * target, 'ro'|'rw']. The exit code is the command's.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $mounts
     * @param  list<string>  $argv
     * @return list<string>
     */
    public function run(string $image, array $mounts, array $argv): array
    {
        $values = [Arguments::image($image), (string) count($mounts)];
        foreach ($mounts as [$source, $target, $mode]) {
            array_push($values, Arguments::operand($source), Arguments::mountPath($target), self::mountMode($mode));
        }

        return $this->ask(__FUNCTION__, [...$values, ...self::command($argv)]);
    }

    /**
     * One of the engine's own files, by its broker key, written as $target in $container by its default user.
     *
     * @return list<string>
     */
    public function putEngineFile(string $container, string $file, string $target): array
    {
        return $this->ask(__FUNCTION__, [Arguments::name($container), Arguments::name($file), Arguments::path($target)]);
    }

    /**
     * $path of $image, read from a container created and removed for it.
     *
     * @return list<string>
     */
    public function readImageFile(string $image, string $path): array
    {
        return $this->ask(__FUNCTION__, [Arguments::image($image), Arguments::path($path)]);
    }

    /**
     * The container's `net/tcp` and `net/tcp6`; nothing when it does not run.
     *
     * @return list<string>
     */
    public function listeningSockets(string $container): array
    {
        return $this->ask(__FUNCTION__, [Arguments::name($container)]);
    }

    /**
     * Tries every container; with $force a missing one is no failure, with $volumes its anonymous volumes go too.
     *
     * @return list<string>
     */
    public function removeContainers(bool $force, bool $volumes, string ...$containers): array
    {
        return $this->ask(__FUNCTION__, [
            $force ? 'force' : '', $volumes ? 'volumes' : '', ...array_map(Arguments::name(...), array_values($containers)),
        ]);
    }

    /** @return list<string> */
    public function createVolume(string $volume): array
    {
        return $this->ask(__FUNCTION__, [Arguments::name($volume)]);
    }

    /**
     * A JSON list with each volume's inspect, or null for one the daemon does not have.
     *
     * @return list<string>
     */
    public function volumes(string ...$volumes): array
    {
        return $this->ask(__FUNCTION__, array_map(Arguments::name(...), array_values($volumes)));
    }

    /** @return list<string> */
    public function listVolumes(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function pruneAnonymousVolumes(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /**
     * Every image no container uses.
     *
     * @return list<string>
     */
    public function pruneImages(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /** @return list<string> */
    public function pruneBuildCache(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /**
     * `docker system prune -af`, and the anonymous volumes with $volumes.
     *
     * @return list<string>
     */
    public function pruneSystem(bool $volumes): array
    {
        return $this->ask(__FUNCTION__, [$volumes ? 'volumes' : '']);
    }

    /**
     * The build cache's size in bytes, as `docker buildx du` totals it.
     *
     * @return list<string>
     */
    public function buildCacheBytes(): array
    {
        return $this->ask(__FUNCTION__, []);
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private static function pairs(array $environment): array
    {
        $values = [(string) count($environment)];
        foreach ($environment as $name => $value) {
            array_push($values, Arguments::environmentName((string) $name), self::withoutNul($value));
        }

        return $values;
    }

    /**
     * @param  list<string>  $argv
     * @return list<string>
     */
    private static function command(array $argv): array
    {
        if ($argv === []) {
            throw InvalidArgument::of('command', '');
        }

        return array_map(self::withoutNul(...), $argv);
    }

    private static function withoutNul(string $value): string
    {
        if (str_contains($value, "\0")) {
            throw InvalidArgument::of('value', $value);
        }

        return $value;
    }

    private static function mountMode(string $mode): string
    {
        if (! in_array($mode, ['ro', 'rw'], true)) {
            throw InvalidArgument::of('mount mode', $mode);
        }

        return $mode;
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function ask(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, [Arguments::username($this->username), ...$values]);
    }
}
