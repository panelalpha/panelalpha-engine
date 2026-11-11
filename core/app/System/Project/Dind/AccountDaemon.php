<?php

namespace App\System\Project\Dind;

use App\Exceptions\DockerErrorException;
use App\Lib\Deploy\Compose\ComposePsRow;
use App\System\Privileged;
use App\System\Privileged\RootAccountDocker;
use App\System\Project\Dind as DindProject;

/**
 * The account's own dockerd through the root broker's Engine API calls, not the docker CLI in the account. A failure
 * throws as {@see System::exec()} does, a {@see DockerErrorException} for daemon errors and a stopped account.
 */
final class AccountDaemon
{
    private const TIMEOUT_SECONDS = 60;

    private const PROJECT_LABEL = 'com.docker.compose.project';

    private const SERVICE_LABEL = 'com.docker.compose.service';

    private const ONE_OFF_LABEL = 'com.docker.compose.oneoff';

    private const CONFIG_HASH_LABEL = 'com.docker.compose.config-hash';

    private const PULL_TIMEOUT_SECONDS = 900;

    private const EXEC_TIMEOUT_SECONDS = 3600;

    private const PRUNE_TIMEOUT_SECONDS = 600;

    public function __construct(private readonly DindProject $project) {}

    /** Whether the daemon answers a ping; never throws. */
    public function answers(int $timeout = self::TIMEOUT_SECONDS): bool
    {
        try {
            $this->run($this->docker()->ping(), $timeout);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * Each container's inspect, in the order asked, null for one the daemon does not have.
     *
     * @param  list<string>  $containers
     * @return list<array<string, mixed>|null>
     */
    public function containers(array $containers, int $timeout = self::TIMEOUT_SECONDS): array
    {
        return $containers === [] ? [] : $this->decoded($this->docker()->containers(...$containers), $timeout);
    }

    /** @return array<string, mixed>|null */
    public function container(string $container, int $timeout = self::TIMEOUT_SECONDS): ?array
    {
        return $this->containers([$container], $timeout)[0] ?? null;
    }

    /**
     * The rows `docker ps [-a] --filter …` lists: `Id`, `Names`, `Image`, `ImageID`, `Command`, `Created`, `State`,
     * `Status`, `Labels`, `Mounts` and the `NetworkSettings` networks' names.
     *
     * @param  array<string, list<string>>  $filters
     * @return list<array<string, mixed>>
     */
    public function listContainers(bool $all, array $filters = [], int $timeout = self::TIMEOUT_SECONDS): array
    {
        return array_values(array_filter($this->decoded($this->docker()->listContainers($all, $filters), $timeout), 'is_array'));
    }

    /**
     * Full ids of the containers `docker ps [-a] --filter …` lists.
     *
     * @param  array<string, list<string>>  $filters
     * @return list<string>
     */
    public function containerIds(bool $all, array $filters = [], int $timeout = self::TIMEOUT_SECONDS): array
    {
        return array_map(static fn (array $row): string => (string) $row['Id'], $this->listContainers($all, $filters, $timeout));
    }

    /**
     * The app stack's containers as `docker compose ps` finds them: running unless $stopped, no one-off containers
     * unless $oneOffs, only $service's when given, sorted by name.
     *
     * @return list<array<string, mixed>> {@see listContainers()} rows
     */
    public function stackContainers(bool $stopped = false, bool $oneOffs = false, ?string $service = null, int $timeout = self::TIMEOUT_SECONDS): array
    {
        $labels = [self::PROJECT_LABEL.'='.$this->project->userAppComposeProjectName(), self::CONFIG_HASH_LABEL];
        if (! $oneOffs) {
            $labels[] = self::ONE_OFF_LABEL.'=False';
        }
        if ($service !== null) {
            $labels[] = self::SERVICE_LABEL.'='.$service;
        }
        $rows = $this->listContainers($stopped, ['label' => $labels], $timeout);
        usort($rows, static fn (array $a, array $b): int => strcmp(ComposePsRow::nameOf($a), ComposePsRow::nameOf($b)));

        return $rows;
    }

    /**
     * Full ids of {@see stackContainers()}, as `docker compose ps --quiet` prints them.
     *
     * @return list<string>
     */
    public function stackContainerIds(bool $stopped = false, bool $oneOffs = false, ?string $service = null, int $timeout = self::TIMEOUT_SECONDS): array
    {
        return array_map(static fn (array $row): string => (string) $row['Id'], $this->stackContainers($stopped, $oneOffs, $service, $timeout));
    }

    /**
     * The stack's containers as `docker compose ps --format json [--all]` prints them, each with every field compose
     * prints ({@see ComposePsRow}); compose fails when a container it listed has gone, and so does this.
     *
     * @return list<array<string, mixed>>
     */
    public function stackRows(bool $all = false, int $timeout = self::TIMEOUT_SECONDS): array
    {
        $rows = $this->stackContainers($all, $all, null, $timeout);
        $inspected = $this->containers(array_map(static fn (array $row): string => (string) $row['Id'], $rows), $timeout);
        $now = time();
        $printed = [];
        foreach ($rows as $i => $row) {
            $inspect = $inspected[$i] ?? throw new DockerErrorException('Error response from daemon: No such container: '.$row['Id']);
            $printed[] = ComposePsRow::of($row, $inspect, $now);
        }

        return $printed;
    }

    /** {@see stackRows()} one JSON object a line, as compose prints them. */
    public function stackPs(bool $all = false, int $timeout = self::TIMEOUT_SECONDS): string
    {
        $lines = '';
        foreach ($this->stackRows($all, $timeout) as $row) {
            $lines .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }

        return $lines;
    }

    /**
     * Each image's inspect, in the order asked, null for one the daemon does not have.
     *
     * @param  list<string>  $images
     * @return list<array<string, mixed>|null>
     */
    public function images(array $images, int $timeout = self::TIMEOUT_SECONDS): array
    {
        return $images === [] ? [] : $this->decoded($this->docker()->images(...$images), $timeout);
    }

    /** @return array<string, mixed>|null */
    public function image(string $image, int $timeout = self::TIMEOUT_SECONDS): ?array
    {
        return $this->images([$image], $timeout)[0] ?? null;
    }

    /** Held with its config, which is what `docker image history` asks for; false when the daemon cannot say. */
    public function hasImage(string $image, int $timeout = self::TIMEOUT_SECONDS): bool
    {
        try {
            $this->run($this->docker()->hasImage($image), $timeout);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * One `repository:tag` per tag the daemon holds, as `docker images --format '{{.Repository}}:{{.Tag}}'` prints
     * them: `repository:<none>` for an image held only by digest, `<none>:<none>` for one with neither.
     *
     * @return list<string>
     */
    public function imageTags(int $timeout = self::TIMEOUT_SECONDS): array
    {
        $tags = [];
        foreach ($this->decoded($this->docker()->listImages(), $timeout) as $image) {
            array_push($tags, ...self::rowsOf(is_array($image) ? $image : []));
        }

        return $tags;
    }

    public function tag(string $source, string $target, int $timeout = self::TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->tag($source, $target), $timeout);
    }

    /**
     * Tries every image and throws when any could not go; with $force a missing one is no failure.
     *
     * @param  list<string>  $images
     */
    public function removeImages(array $images, bool $force = false, int $timeout = self::TIMEOUT_SECONDS): void
    {
        if ($images !== []) {
            $this->run($this->docker()->removeImages($force, ...$images), $timeout);
        }
    }

    /** With $loginDirectory, the project's registry login there goes with the pull. */
    public function pull(string $image, ?string $loginDirectory = null, int $timeout = self::PULL_TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->pull($image, $loginDirectory), $timeout);
    }

    /**
     * $argv in $container, as $user in $workdir when given; never throws for the command's own failure.
     *
     * @param  list<string>  $argv
     * @param  array<string, string>  $environment
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    public function exec(
        string $container,
        array $argv,
        string $user = '',
        string $workdir = '',
        array $environment = [],
        int $timeout = self::EXEC_TIMEOUT_SECONDS,
    ): array {
        $process = $this->project->system()->runProcess($this->docker()->exec($container, $argv, $user, $workdir, $environment), [], $timeout);

        return ['exit_code' => $process->getExitCode() ?? 1, 'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
    }

    /**
     * $argv in a new container of $image, removed once it exits; throws when it fails. Each mount is [named volume or
     * path in the home, target, 'ro'|'rw'].
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $mounts
     * @param  list<string>  $argv
     */
    public function runOnce(string $image, array $mounts, array $argv, int $timeout = self::EXEC_TIMEOUT_SECONDS): string
    {
        return $this->run($this->docker()->run($image, $mounts, $argv), $timeout);
    }

    /** One of the engine's own files, by its broker key, written as $target in $container by its default user. */
    public function putEngineFile(string $container, string $file, string $target, int $timeout = self::TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->putEngineFile($container, $file, $target), $timeout);
    }

    /** $path of $image, read from a container created and removed for it. */
    public function readImageFile(string $image, string $path, int $timeout = self::TIMEOUT_SECONDS): string
    {
        return $this->run($this->docker()->readImageFile($image, $path), $timeout);
    }

    /** The container's `net/tcp` and `net/tcp6` as `cat` prints them; empty when it does not run. */
    public function listeningSockets(string $container, int $timeout = self::TIMEOUT_SECONDS): string
    {
        return $this->run($this->docker()->listeningSockets($container), $timeout);
    }

    /**
     * Tries every container and throws when any could not go; with $force a missing one is no failure.
     *
     * @param  list<string>  $containers
     */
    public function removeContainers(array $containers, bool $force = true, bool $volumes = false, int $timeout = self::TIMEOUT_SECONDS): void
    {
        if ($containers !== []) {
            $this->run($this->docker()->removeContainers($force, $volumes, ...$containers), $timeout);
        }
    }

    public function createVolume(string $volume, int $timeout = self::TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->createVolume($volume), $timeout);
    }

    /**
     * Each volume's inspect, in the order asked, null for one the daemon does not have.
     *
     * @param  list<string>  $volumes
     * @return list<array<string, mixed>|null>
     */
    public function volumes(array $volumes, int $timeout = self::TIMEOUT_SECONDS): array
    {
        return $volumes === [] ? [] : $this->decoded($this->docker()->volumes(...$volumes), $timeout);
    }

    /**
     * The volume's inspect; for one the daemon does not have, the error `docker volume inspect` fails with.
     *
     * @return array<string, mixed>
     */
    public function volume(string $volume, int $timeout = self::TIMEOUT_SECONDS): array
    {
        return $this->volumes([$volume], $timeout)[0]
            ?? throw new DockerErrorException("Error response from daemon: get {$volume}: no such volume");
    }

    /** @return list<string> */
    public function volumeNames(int $timeout = self::TIMEOUT_SECONDS): array
    {
        $names = [];
        foreach ($this->decoded($this->docker()->listVolumes(), $timeout) as $volume) {
            if (is_array($volume) && is_string($volume['Name'] ?? null)) {
                $names[] = $volume['Name'];
            }
        }

        return $names;
    }

    public function pruneAnonymousVolumes(int $timeout = self::PRUNE_TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->pruneAnonymousVolumes(), $timeout);
    }

    /** Every image no container uses. */
    public function pruneImages(int $timeout = self::PRUNE_TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->pruneImages(), $timeout);
    }

    public function pruneBuildCache(int $timeout = self::PRUNE_TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->pruneBuildCache(), $timeout);
    }

    /** `docker system prune -af`, and the anonymous volumes with $volumes. */
    public function pruneSystem(bool $volumes, int $timeout = self::PRUNE_TIMEOUT_SECONDS): void
    {
        $this->run($this->docker()->pruneSystem($volumes), $timeout);
    }

    /** The build cache's size in bytes; null when the daemon could not say. */
    public function buildCacheBytes(int $timeout = self::TIMEOUT_SECONDS): ?int
    {
        try {
            $bytes = trim($this->run($this->docker()->buildCacheBytes(), $timeout));
        } catch (\Throwable) {
            return null;
        }

        return preg_match('/\A\d+\z/', $bytes) === 1 ? (int) $bytes : null;
    }

    /** Both streams of the container's last $lines lines, in the order it wrote them; with $timestamps each led by its time. */
    public function logTail(string $container, int $lines, int $timeout = self::TIMEOUT_SECONDS, bool $timestamps = false): string
    {
        return $this->run($this->docker()->logTail($container, $lines, $timestamps), $timeout);
    }

    /**
     * @param  array<string, mixed>  $image
     * @return list<string>
     */
    private static function rowsOf(array $image): array
    {
        $tags = is_array($image['RepoTags'] ?? null) ? array_values($image['RepoTags']) : [];
        $digests = is_array($image['RepoDigests'] ?? null) ? array_values($image['RepoDigests']) : [];
        if (self::isDangling($tags, $digests)) {
            return ['<none>:<none>'];
        }
        $tagged = self::familiarRepositories($tags, ':');
        $digested = self::familiarRepositories($digests, '@');
        $rows = [];
        foreach ($tagged as [$repository, $tag]) {
            $rows[] = $repository.':'.$tag;
        }
        $taggedRepositories = array_column($tagged, 0);
        foreach (array_unique(array_column($digested, 0)) as $repository) {
            if (! in_array($repository, $taggedRepositories, true)) {
                $rows[] = $repository.':<none>';
            }
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $tags
     * @param  list<mixed>  $digests
     */
    private static function isDangling(array $tags, array $digests): bool
    {
        return ($tags === [] && $digests === []) || ($tags === ['<none>:<none>'] && $digests === ['<none>@<none>']);
    }

    /**
     * Each reference as [familiar repository, tag or digest], `docker.io/library/` dropped as the CLI does.
     *
     * @param  array<int, mixed>  $references
     * @return list<array{0: string, 1: string}>
     */
    private static function familiarRepositories(array $references, string $separator): array
    {
        $split = [];
        foreach ($references as $reference) {
            if (! is_string($reference)) {
                continue;
            }
            $at = $separator === ':' ? self::tagSeparator($reference) : strpos($reference, '@');
            if ($at === false) {
                continue;
            }
            $repository = preg_replace('~\Adocker\.io/(?:library/)?~', '', substr($reference, 0, $at)) ?? '';
            if ($repository === '' || $repository === '<none>') {
                continue;
            }
            $split[] = [$repository, substr($reference, $at + 1)];
        }

        return $split;
    }

    private static function tagSeparator(string $reference): int|false
    {
        $colon = strrpos($reference, ':');
        $slash = strrpos($reference, '/');

        return $colon !== false && ($slash === false || $colon > $slash) ? $colon : false;
    }

    /**
     * @param  list<string>  $argv
     * @return list<mixed>
     */
    private function decoded(array $argv, int $timeout): array
    {
        $decoded = json_decode($this->run($argv, $timeout), true);
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new \RuntimeException("The root broker answered {$this->project->username()}'s Docker request with no JSON list");
        }

        return $decoded;
    }

    /** @param  list<string>  $argv */
    private function run(array $argv, int $timeout): string
    {
        return $this->project->system()->exec($argv, [], $timeout);
    }

    private function docker(): RootAccountDocker
    {
        return Privileged::accountDocker($this->project->username());
    }
}
