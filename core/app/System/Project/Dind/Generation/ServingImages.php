<?php

namespace App\System\Project\Dind\Generation;

use App\Exceptions\DockerErrorException;
use App\System\Privileged\InvalidArgument;
use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * The image each running container was started from, held by a tag of its own before a redeploy builds:
 * a build retags `project-app` even when the deploy fails, so each name whose container still runs is pointed back.
 */
final class ServingImages
{
    private const TIMEOUT_SECONDS = 30;

    /** Holds the image a running container uses while its own name moves to a new build. */
    private const KEEP_REPOSITORY = 'panelalpha-serving';

    public function __construct(private readonly DindProject $project) {}

    /** Holds and notes the image of every running container; only those a tag now holds are noted. */
    public function remember(): void
    {
        $inspected = $this->inspectRunning();
        if ($inspected === null) {
            return;
        }
        $images = self::parse($inspected);
        $held = $this->hold($images);
        $images = array_values(array_filter($images, static fn (array $image): bool => self::isHeld($image, $held)));
        $state = new GenerationState($this->project->username());
        $images === [] ? $state->forget(GenerationState::IMAGES) : $state->put(GenerationState::IMAGES, ['containers' => $images]);
    }

    /**
     * Every running container's inspect; null when they could not be read, or one is gone since it was listed.
     *
     * @return list<array<string, mixed>>|null
     */
    private function inspectRunning(): ?array
    {
        try {
            $ids = $this->project->accountDaemon()->stackContainerIds(timeout: self::TIMEOUT_SECONDS);
            if ($ids === []) {
                return [];
            }
            $inspected = $this->project->accountDaemon()->containers($ids, self::TIMEOUT_SECONDS);
            foreach ($inspected as $at => $container) {
                if ($container === null) {
                    throw new \RuntimeException("No such container: {$ids[$at]}");
                }
            }
        } catch (\Throwable $e) {
            $this->couldNotHold('its running containers could not be read: '.$e->getMessage());

            return null;
        }

        return $inspected;
    }

    /**
     * @param  list<array{id: string, image: string, ref: string}>  $images
     * @return list<string> the holding tags that were set
     */
    private function hold(array $images): array
    {
        $held = [];
        try {
            foreach ($images as $image) {
                if ($this->tagged($image['image'], self::keepTag($image['image']))) {
                    $held[] = self::keepTag($image['image']);
                }
            }
        } catch (\Throwable $e) {
            $this->couldNotHold('their images could not be tagged: '.$e->getMessage());

            return [];
        }
        $this->warnUnheld($images, $held);

        return $held;
    }

    /**
     * A tag the daemon refused is skipped, so an image missing from $held is the only sign of it.
     *
     * @param  list<array{id: string, image: string, ref: string}>  $images
     * @param  list<string>  $held
     */
    private function warnUnheld(array $images, array $held): void
    {
        $unheld = array_filter($images, static fn (array $image): bool => ! self::isHeld($image, $held));
        if ($unheld === []) {
            return;
        }
        $names = implode(', ', array_map(static fn (array $image): string => $image['ref'].' ('.$image['image'].')', $unheld));
        $this->couldNotHold($names.' could not be tagged');
    }

    /** Whether the tag that holds this image is one of the tags set. */
    private static function isHeld(array $image, array $held): bool
    {
        return in_array(self::keepTag($image['image']), $held, true);
    }

    /** False when the daemon refused the tag; a throw when the account could not be asked. */
    private function tagged(string $source, string $target): bool
    {
        try {
            $this->project->accountDaemon()->tag($source, $target, self::TIMEOUT_SECONDS);
        } catch (InvalidArgument) {
            return false;
        } catch (DockerErrorException $e) {
            if ($e->isContainerUnavailable()) {
                throw $e;
            }

            return false;
        }

        return true;
    }

    /**
     * The holding tag of each container's image, once; a tag that will not go stays.
     *
     * @param  list<array{id: string, image: string, ref: string}>  $containers
     */
    private function dropHoldingTags(array $containers): void
    {
        $keep = array_values(array_unique(array_map(static fn (array $container): string => self::keepTag($container['image']), $containers)));
        try {
            $this->project->accountDaemon()->removeImages($keep, timeout: self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
        }
    }

    /** Said in the deploy log too: a later rollback would only report the images gone. */
    private function couldNotHold(string $why): void
    {
        $message = "Could not keep the images {$this->project->username()} is serving, so the previous "
            .'version will not be startable after a redeploy: '.$why;
        Log::warning($message);
        try {
            $this->project->shell()->logger()?->warn($message);
        } catch (\Throwable) {
        }
    }

    /** `panelalpha-serving:<12 hex of the image id>`. */
    public static function keepTag(string $imageId): string
    {
        return self::KEEP_REPOSITORY.':'.substr(preg_replace('/^sha256:/', '', $imageId) ?? '', 0, 12);
    }

    /**
     * Points each name back at the image its still-running container runs, then lets go of the holding tags, all names
     * first as two containers may share an image. Null when nothing was noted, or the account could not be asked.
     */
    public function settle(): ?int
    {
        $state = new GenerationState($this->project->username());
        $noted = $state->get(GenerationState::IMAGES);
        if ($noted === null) {
            return null;
        }
        $containers = self::containersIn($noted);
        $retagged = 0;
        try {
            $inspected = $containers === [] ? [] : $this->project->accountDaemon()->containers(array_column($containers, 'id'), self::TIMEOUT_SECONDS);
            foreach ($containers as $at => $container) {
                if (self::runs($inspected[$at] ?? null, $container['image']) && $this->tagged(self::keepTag($container['image']), $container['ref'])) {
                    $retagged++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Could not point the image tags of {$this->project->username()} back: ".$e->getMessage());

            return null;
        }
        $this->dropHoldingTags($containers);
        $state->forget(GenerationState::IMAGES);

        return $retagged;
    }

    /** @param  ?array<string, mixed>  $container */
    private static function runs(?array $container, string $image): bool
    {
        return ($container['State']['Running'] ?? null) === true && ($container['Image'] ?? null) === $image;
    }

    /** Whether every image noted is still held by its tag: what a start of the previous version needs. */
    public function stillHeld(): bool
    {
        $noted = $this->noted();
        if ($noted === []) {
            return false;
        }
        try {
            $held = $this->project->accountDaemon()->images(array_map(static fn (array $container): string => self::keepTag($container['image']), $noted), self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return false;
        }
        foreach ($noted as $at => $container) {
            if (($held[$at]['Id'] ?? null) !== $container['image']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Every name back on the image its container ran before the redeploy, whether that container still runs or not;
     * then the holding tags go. Null when nothing was noted, or the account could not be asked.
     */
    public function restoreNames(): ?int
    {
        $noted = $this->noted();
        if ($noted === []) {
            return null;
        }
        $restored = 0;
        try {
            foreach ($noted as $container) {
                if ($this->tagged(self::keepTag($container['image']), $container['ref'])) {
                    $restored++;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("Could not point the image tags of {$this->project->username()} at the previous version: ".$e->getMessage());

            return null;
        }
        $this->dropHoldingTags($noted);
        (new GenerationState($this->project->username()))->forget(GenerationState::IMAGES);

        return $restored;
    }

    /**
     * What {@see remember()} noted.
     *
     * @return list<array{id: string, image: string, ref: string}>
     */
    public function noted(): array
    {
        return self::containersIn((new GenerationState($this->project->username()))->get(GenerationState::IMAGES));
    }

    /**
     * @param  ?array<string, mixed>  $noted  the IMAGES entry
     * @return list<array{id: string, image: string, ref: string}>
     */
    private static function containersIn(?array $noted): array
    {
        $containers = [];
        foreach ((array) ($noted['containers'] ?? []) as $container) {
            if (is_array($container) && isset($container['id'], $container['image'], $container['ref'])) {
                $containers[] = ['id' => (string) $container['id'], 'image' => (string) $container['image'], 'ref' => (string) $container['ref']];
            }
        }

        return $containers;
    }

    /**
     * Each container's id, image id and the reference it was created from; a reference pinned to a digest cannot be retagged.
     *
     * @param  list<array<string, mixed>|null>  $inspected
     * @return list<array{id: string, image: string, ref: string}>
     */
    public static function parse(array $inspected): array
    {
        $images = [];
        foreach ($inspected as $container) {
            $id = (string) ($container['Id'] ?? '');
            $image = (string) ($container['Image'] ?? '');
            $ref = (string) ($container['Config']['Image'] ?? '');
            if ($id === '' || $ref === '' || ! str_starts_with($image, 'sha256:') || str_contains($ref, '@')) {
                continue;
            }
            $images[] = ['id' => $id, 'image' => $image, 'ref' => $ref];
        }

        return $images;
    }
}
