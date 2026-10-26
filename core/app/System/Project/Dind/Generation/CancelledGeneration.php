<?php

namespace App\System\Project\Dind\Generation;

use App\Lib\Deploy\DeployLog\DeployLogger;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * What a cancelled redeploy created in the app's compose project: the containers created since it started,
 * and the images they run that are as new. A container that was there before stays, started or stopped.
 */
final class CancelledGeneration
{
    private const TIMEOUT_SECONDS = 120;

    /** `docker inspect` per container: id, when it was created, its image id. */
    private const CONTAINER_FORMAT = '{{.Id}} {{.Created}} {{.Image}}';

    /** Removes the containers named before `--`, then each image after it that no container uses. */
    private const REMOVE_SCRIPT = 'ids=(); while [ $# -gt 0 ] && [ "$1" != "--" ]; do ids+=("$1"); shift; done; shift; '
        .'docker rm -f "${ids[@]}" >/dev/null || exit 1; for image in "$@"; do docker rmi "$image" >/dev/null 2>&1; done; true';

    public function __construct(private readonly DindProject $project) {}

    /**
     * Nothing goes while a version it was replacing may be what serves: the sweep settles that.
     *
     * @return list<string> the containers removed
     */
    public function remove(): array
    {
        $since = $this->deployStartedAt();
        if ($since === null || ! $this->replacedNothingRunning()) {
            return [];
        }
        $containers = self::createdSince((string) $this->inspectProject(), $since);
        if ($containers === []) {
            return [];
        }
        $images = self::createdSince((string) $this->inspectImages(array_values(array_unique(array_column($containers, 'image')))), $since);

        return $this->removeAll(array_column($containers, 'id'), array_column($images, 'id'));
    }

    /**
     * Lines of `<id> <created> [<image>]` created at or after $since.
     *
     * @return list<array{id: string, image: string}>
     */
    public static function createdSince(string $inspect, int $since): array
    {
        $created = [];
        foreach (preg_split('/\R/', trim($inspect)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $at = count($fields) >= 2 ? self::timestamp($fields[1]) : null;
            if ($at !== null && $at >= $since) {
                $created[] = ['id' => $fields[0], 'image' => $fields[2] ?? ''];
            }
        }

        return $created;
    }

    /** Docker's RFC 3339 time, its fraction dropped. */
    private static function timestamp(string $created): ?int
    {
        $at = strtotime((string) preg_replace('/\.\d+/', '', $created));

        return $at === false ? null : $at;
    }

    private function deployStartedAt(): ?int
    {
        $started = DeployLogger::readLatestFor($this->project->username())['started_at'] ?? null;

        return is_int($started) ? $started : null;
    }

    /** No running version was noted, or every container of the one noted still runs. */
    private function replacedNothingRunning(): bool
    {
        $entry = (new RoutingSnapshot($this->project))->entry();
        if ($entry === null) {
            return true;
        }

        return CheckoutAside::allRunning($this->project, array_values(array_filter((array) ($entry['containers'] ?? []), 'is_string'))) === true;
    }

    private function inspectProject(): ?string
    {
        $ps = implode(' ', array_map('escapeshellarg', $this->project->userAppComposeCommand(['ps', '--all', '--quiet'])));
        $script = 'ids=$('.$ps.') || exit 1; [ -n "$ids" ] || exit 0; docker inspect --format '
            .escapeshellarg(self::CONTAINER_FORMAT).' $ids';

        return $this->quietly(fn (): string => $this->project->shell()->execAsUserQuiet(['bash', '-c', $script], [], self::TIMEOUT_SECONDS));
    }

    /** @param list<string> $images */
    private function inspectImages(array $images): ?string
    {
        $images = array_values(array_filter($images, static fn (string $image): bool => $image !== ''));
        if ($images === []) {
            return null;
        }

        return $this->quietly(fn (): string => $this->project->shell()->execQuiet(
            ['docker', 'image', 'inspect', '--format', '{{.Id}} {{.Created}}', ...$images],
            [],
            self::TIMEOUT_SECONDS
        ));
    }

    /**
     * @param  list<string>  $containers
     * @param  list<string>  $images
     * @return list<string>
     */
    private function removeAll(array $containers, array $images): array
    {
        $removed = $this->quietly(fn (): string => $this->project->shell()->execQuiet(
            ['bash', '-c', self::REMOVE_SCRIPT, 'remove', ...$containers, '--', ...$images],
            [],
            self::TIMEOUT_SECONDS
        ));
        if ($removed === null) {
            return [];
        }
        Log::info("Removed what the cancelled redeploy of {$this->project->username()} created: "
            .count($containers).' container(s), and their new images where nothing else uses them');

        return $containers;
    }

    /** @param \Closure(): string $exec */
    private function quietly(\Closure $exec): ?string
    {
        try {
            return $exec();
        } catch (\Throwable $e) {
            Log::warning("Could not remove what the cancelled redeploy of {$this->project->username()} created: ".$e->getMessage());

            return null;
        }
    }
}
