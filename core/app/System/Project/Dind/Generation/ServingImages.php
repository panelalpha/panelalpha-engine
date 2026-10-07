<?php

namespace App\System\Project\Dind\Generation;

use App\System\Project\Dind as DindProject;
use Illuminate\Support\Facades\Log;

/**
 * The image each running container was started from, noted and held by a tag
 * of its own before a redeploy builds. A build retags `project-app` even when
 * the deploy then fails, and the old image is dropped with its name: after the
 * deploy, a name whose container still runs is pointed back at what it runs.
 */
final class ServingImages
{
    private const TIMEOUT_SECONDS = 30;

    /** `docker inspect` per running container: id, image id, the reference it was created from. */
    private const INSPECT_FORMAT = '{{.Id}} {{.Image}} {{.Config.Image}}';

    /** Holds the image a running container uses while its own name moves to a new build. */
    private const KEEP_REPOSITORY = 'panelalpha-serving';

    public function __construct(private readonly DindProject $project)
    {
    }

    public function remember(): void
    {
        $ps = implode(' ', array_map('escapeshellarg', $this->project->userAppComposeCommand(['ps', '--quiet'])));
        $script = 'ids=$(' . $ps . ') || exit 1; [ -n "$ids" ] || exit 0; docker inspect --format '
            . escapeshellarg(self::INSPECT_FORMAT) . ' $ids';
        try {
            $raw = $this->project->shell()->execAsUserQuiet(['bash', '-c', $script], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable) {
            return;
        }
        $images = self::parse($raw);
        $args = [];
        foreach ($images as $image) {
            array_push($args, $image['image'], self::keepTag($image['image']));
        }
        $script = 'while [ $# -ge 2 ]; do docker tag "$1" "$2" >/dev/null 2>&1 && echo "$2"; shift 2; done';
        $held = [];
        try {
            if ($args !== []) {
                $out = $this->project->shell()->execQuiet(['bash', '-c', $script, 'hold', ...$args], [], self::TIMEOUT_SECONDS);
                $held = preg_split('/\R/', trim($out)) ?: [];
            }
        } catch (\Throwable) {
        }
        $images = array_values(array_filter($images, static fn (array $image): bool => in_array(self::keepTag($image['image']), $held, true)));
        $state = new GenerationState($this->project->username());
        $images === [] ? $state->forget(GenerationState::IMAGES) : $state->put(GenerationState::IMAGES, ['containers' => $images]);
    }

    /** `panelalpha-serving:<12 hex of the image id>`. */
    public static function keepTag(string $imageId): string
    {
        return self::KEEP_REPOSITORY . ':' . substr(preg_replace('/^sha256:/', '', $imageId) ?? '', 0, 12);
    }

    /**
     * Points each name back at the image its still-running container runs,
     * then lets go of the holding tags. Null when nothing was noted, or the
     * account could not be asked.
     */
    public function settle(): ?int
    {
        $state = new GenerationState($this->project->username());
        $noted = $state->get(GenerationState::IMAGES);
        if ($noted === null) {
            return null;
        }
        $args = [];
        foreach ((array) ($noted['containers'] ?? []) as $container) {
            if (is_array($container) && isset($container['id'], $container['image'], $container['ref'])) {
                array_push($args, (string) $container['id'], (string) $container['image'], (string) $container['ref'], self::keepTag((string) $container['image']));
            }
        }
        // Every name first, then the holding tags: two containers may share an image.
        $script = 'n=0; keep=; while [ $# -ge 4 ]; do '
            . '[ "$(docker inspect --format "{{.State.Running}} {{.Image}}" "$1" 2>/dev/null)" = "true $2" ] '
            . '&& docker tag "$4" "$3" && n=$((n+1)); keep="$keep $4"; shift 4; done; '
            . 'for k in $(printf "%s\\n" $keep | sort -u); do docker rmi "$k" >/dev/null 2>&1; done; echo $n';
        try {
            $out = $args === [] ? '0' : $this->project->shell()->execQuiet(['bash', '-c', $script, 'retag', ...$args], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            Log::warning("Could not point the image tags of {$this->project->username()} back: " . $e->getMessage());

            return null;
        }
        $state->forget(GenerationState::IMAGES);

        return (int) trim($out);
    }

    /** Whether every image noted is still held by its tag: what a start of the previous version needs. */
    public function stillHeld(): bool
    {
        $args = [];
        foreach ($this->noted() as $container) {
            array_push($args, self::keepTag($container['image']), $container['image']);
        }
        if ($args === []) {
            return false;
        }
        $script = 'while [ $# -ge 2 ]; do [ "$(docker image inspect --format "{{.Id}}" "$1" 2>/dev/null)" = "$2" ] || { echo gone; exit 0; }; shift 2; done; echo held';
        try {
            return trim($this->project->shell()->execQuiet(['bash', '-c', $script, 'held', ...$args], [], self::TIMEOUT_SECONDS)) === 'held';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every name back on the image its container ran before the redeploy,
     * whether that container still runs or not; then the holding tags go.
     * Null when nothing was noted, or the account could not be asked.
     */
    public function restoreNames(): ?int
    {
        $args = [];
        foreach ($this->noted() as $container) {
            array_push($args, $container['ref'], self::keepTag($container['image']));
        }
        if ($args === []) {
            return null;
        }
        $script = 'n=0; keep=; while [ $# -ge 2 ]; do docker tag "$2" "$1" && n=$((n+1)); keep="$keep $2"; shift 2; done; '
            . 'for k in $(printf "%s\\n" $keep | sort -u); do docker rmi "$k" >/dev/null 2>&1; done; echo $n';
        try {
            $out = $this->project->shell()->execQuiet(['bash', '-c', $script, 'restore', ...$args], [], self::TIMEOUT_SECONDS);
        } catch (\Throwable $e) {
            Log::warning("Could not point the image tags of {$this->project->username()} at the previous version: " . $e->getMessage());

            return null;
        }
        (new GenerationState($this->project->username()))->forget(GenerationState::IMAGES);

        return (int) trim($out);
    }

    /**
     * What {@see remember()} noted.
     *
     * @return list<array{id: string, image: string, ref: string}>
     */
    public function noted(): array
    {
        $noted = (new GenerationState($this->project->username()))->get(GenerationState::IMAGES);
        $containers = [];
        foreach ((array) ($noted['containers'] ?? []) as $container) {
            if (is_array($container) && isset($container['id'], $container['image'], $container['ref'])) {
                $containers[] = ['id' => (string) $container['id'], 'image' => (string) $container['image'], 'ref' => (string) $container['ref']];
            }
        }

        return $containers;
    }

    /**
     * Lines of {@see INSPECT_FORMAT}; a reference pinned to a digest cannot be retagged.
     *
     * @return list<array{id: string, image: string, ref: string}>
     */
    public static function parse(string $inspect): array
    {
        $images = [];
        foreach (preg_split('/\R/', trim($inspect)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            if (count($fields) !== 3 || !str_starts_with($fields[1], 'sha256:') || str_contains($fields[2], '@')) {
                continue;
            }
            $images[] = ['id' => $fields[0], 'image' => $fields[1], 'ref' => $fields[2]];
        }

        return $images;
    }
}
