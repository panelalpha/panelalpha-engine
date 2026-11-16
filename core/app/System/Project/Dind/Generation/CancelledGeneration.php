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

    public function __construct(private readonly DindProject $project) {}

    /**
     * Nothing goes while a version it was replacing may be what serves: the sweep settles that.
     *
     * @return list<string> the containers removed
     */
    public function remove(): array
    {
        $since = $this->deployStartedAt();
        if ($since === null || $this->runFileNotWrittenYet() || ! $this->replacedNothingRunning()) {
            return [];
        }
        $containers = self::createdSince($this->inspectProject() ?? [], $since);
        if ($containers === []) {
            return [];
        }
        $images = self::createdSince($this->inspectImages(array_values(array_unique(array_column($containers, 'image')))) ?? [], $since);

        return $this->removeAll(array_column($containers, 'id'), array_column($images, 'id'));
    }

    /**
     * The containers or images inspected that were created at or after $since, each with its image id if it has one.
     *
     * @param  list<array<string, mixed>|null>  $inspected
     * @return list<array{id: string, image: string}>
     */
    public static function createdSince(array $inspected, int $since): array
    {
        $created = [];
        foreach ($inspected as $item) {
            $id = (string) ($item['Id'] ?? '');
            $at = $id === '' ? null : self::timestamp((string) ($item['Created'] ?? ''));
            if ($at !== null && $at >= $since) {
                $created[] = ['id' => $id, 'image' => (string) ($item['Image'] ?? '')];
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

    /**
     * The checkout is aside and ~/project holds no run file yet: compose cannot read the app's
     * project, and the redeploy has started nothing from a run file it has not written.
     */
    private function runFileNotWrittenYet(): bool
    {
        if ((new GenerationState($this->project->username()))->get(GenerationState::CHECKOUT) === null
            || $this->project->system()->filesystem()->fileExists($this->project->userAppComposeFilePath())) {
            return false;
        }
        Log::info("Nothing to remove for the cancelled redeploy of {$this->project->username()}: "
            .'its checkout is aside and the new run file is not written yet');

        return true;
    }

    /** Nothing ran when the redeploy started, or every container of the version noted still runs. */
    private function replacedNothingRunning(): bool
    {
        $entry = (new RoutingSnapshot($this->project))->entry();
        if ($entry === null) {
            return $this->notedNothingRunning();
        }

        return CheckoutAside::allRunning($this->project, array_values(array_filter((array) ($entry['containers'] ?? []), 'is_string'))) === true;
    }

    /** Only a snapshot that read the app's containers and found none says so: what it could not read may be live. */
    private function notedNothingRunning(): bool
    {
        $state = new GenerationState($this->project->username());
        if ($state->get(GenerationState::UNREAD) !== null) {
            Log::info("Left what the cancelled redeploy of {$this->project->username()} created: "
                .'its containers could not be read when it started, so it may be what serves');

            return false;
        }

        return $state->get(GenerationState::STOPPED) !== null;
    }

    /** @return list<array<string, mixed>>|null */
    private function inspectProject(): ?array
    {
        return $this->quietly(function (): array {
            $ids = $this->project->accountDaemon()->stackContainerIds(true, true, timeout: self::TIMEOUT_SECONDS);

            return $ids === [] ? [] : self::allFound('container', $ids, $this->project->accountDaemon()->containers($ids, self::TIMEOUT_SECONDS));
        });
    }

    /**
     * @param  list<string>  $images
     * @return list<array<string, mixed>>|null
     */
    private function inspectImages(array $images): ?array
    {
        $images = array_values(array_filter($images, static fn (string $image): bool => $image !== ''));
        if ($images === []) {
            return null;
        }

        return $this->quietly(fn (): array => self::allFound('image', $images, $this->project->accountDaemon()->images($images, self::TIMEOUT_SECONDS)));
    }

    /**
     * $inspected, or a throw naming the first one asked that the daemon does not have.
     *
     * @param  list<string>  $asked
     * @param  list<array<string, mixed>|null>  $inspected
     * @return list<array<string, mixed>>
     */
    private static function allFound(string $kind, array $asked, array $inspected): array
    {
        foreach ($inspected as $at => $item) {
            if ($item === null) {
                throw new \RuntimeException("No such {$kind}: {$asked[$at]}");
            }
        }

        return $inspected;
    }

    /**
     * The containers, then each image no container uses; nothing counts as removed when a container stays.
     *
     * @param  list<string>  $containers
     * @param  list<string>  $images
     * @return list<string>
     */
    private function removeAll(array $containers, array $images): array
    {
        $removed = $this->quietly(function () use ($containers): bool {
            $this->project->accountDaemon()->removeContainers($containers, true, false, self::TIMEOUT_SECONDS);

            return true;
        });
        if ($removed === null) {
            return [];
        }
        $this->removeUnused($images);
        Log::info("Removed what the cancelled redeploy of {$this->project->username()} created: "
            .count($containers).' container(s), and their new images where nothing else uses them');

        return $containers;
    }

    /**
     * Every image is tried; one a container still uses stays.
     *
     * @param  list<string>  $images
     */
    private function removeUnused(array $images): void
    {
        try {
            $this->project->accountDaemon()->removeImages($images, false, self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
        }
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $exec
     * @return T|null
     */
    private function quietly(\Closure $exec): mixed
    {
        try {
            return $exec();
        } catch (\Throwable $e) {
            Log::warning("Could not remove what the cancelled redeploy of {$this->project->username()} created: ".$e->getMessage());

            return null;
        }
    }
}
