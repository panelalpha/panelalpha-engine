<?php

namespace App\Lib\Deploy\CacheManager;

use App\Exceptions\NotFoundException;
use App\Exceptions\ProblemException;
use App\Jobs\ApplyOperatorImage;
use App\Models\OperatorImage;
use App\Models\Task;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Adding, changing, refreshing and removing operator images. The API queues
 * each operation as a task; the CLI and the weekly refresh run it in place.
 */
class OperatorImages
{
    public const REF_TAKEN = 'operator_image_ref_taken';

    public const REF_PUBLIC = 'operator_image_ref_public';

    public const REF_UNVERIFIED = 'operator_image_ref_unverified';

    public const SOURCE_INVALID = 'operator_image_source_invalid';

    public const DOCKERFILE_REQUIRED = 'operator_image_dockerfile_required';

    public const DOCKERFILE_INVALID = 'operator_image_dockerfile_invalid';

    public const BUILD_ONLY = 'operator_image_build_only';

    public const BUILD_ARGS_INVALID = 'operator_image_build_args_invalid';

    public const WHY_INVALID = 'operator_image_why_invalid';

    public const NOT_ON_HOST = 'operator_image_not_on_host';

    public const BUSY = 'operator_image_busy';

    public const REFRESH_SKIPPED = 'skipped';

    public const REFRESH_STARTED = 'started';

    public const REFRESH_RENEWED = 'renewed';

    public const REFRESH_KEPT = 'kept';

    public const REFRESH_FAILED = 'failed';

    public const DOCKERFILE_MAX_BYTES = 65536;

    private const BUILD_ARGS_MAX = 50;

    private const BUILD_ARG_VALUE_MAX = 1024;

    private const WHY_MAX = 255;

    private const ERROR_MAX = 4000;

    public function __construct(
        private readonly OperatorImageHost $host,
        private readonly PublicImageLookup $lookup,
    ) {}

    public static function make(): self
    {
        return new self(OperatorImageHost::make(), new PublicImageLookup);
    }

    public static function findOrFail(int $id): OperatorImage
    {
        return OperatorImage::query()->find($id)
            ?? throw new NotFoundException("Operator image {$id} not found.", OperatorImage::class);
    }

    public static function findByRefOrFail(string $ref): OperatorImage
    {
        return OperatorImage::findByRef($ref)
            ?? throw new NotFoundException("Operator image '{$ref}' not found.", OperatorImage::class);
    }

    /**
     * Record a new image, not yet on the registry.
     *
     * @param  array<string, mixed>  $input  ref, source, dockerfile, build_args, why
     *
     * @throws ProblemException
     */
    public function create(array $input): OperatorImage
    {
        $ref = is_string($input['ref'] ?? null) ? trim($input['ref']) : '';
        $source = $this->validSource($input['source'] ?? null);
        $this->validateRef($ref, $source);
        $build = $this->validBuild($source, $input);
        $why = $this->validWhy($input['why'] ?? null);
        if (OperatorImage::findByRef($ref) !== null) {
            throw ProblemException::conflict('ref', self::REF_TAKEN, "'{$ref}' is already an operator image.");
        }
        if ($source === OperatorImage::SOURCE_LOCAL && ! $this->host->isOnHost($ref)) {
            throw ProblemException::one('ref', self::NOT_ON_HOST, "{$ref} is not on this host. Build or load it with docker first.");
        }
        if ($source !== OperatorImage::SOURCE_PULL) {
            $this->refuseDockerHubName($ref);
        }

        return OperatorImage::query()->create([
            'ref' => $ref,
            'source' => $source,
            'dockerfile' => $build['dockerfile'],
            'build_args' => $build['build_args'],
            'why' => $why,
            'status' => OperatorImage::STATUS_PENDING,
        ]);
    }

    /**
     * {@see create()}, then queue its first publish; the image's task_id is that task.
     *
     * @param  array<string, mixed>  $input  ref, source, dockerfile, build_args, why
     *
     * @throws ProblemException
     */
    public function createQueued(array $input): OperatorImage
    {
        $image = $this->create($input);
        $this->queue($image, ApplyOperatorImage::PUBLISH);

        return $image;
    }

    /**
     * Change what an image says about itself. True when the build changed and
     * the image has to be built again.
     *
     * @param  array<string, mixed>  $input  dockerfile, build_args, why
     *
     * @throws ProblemException
     */
    public function update(OperatorImage $image, array $input): bool
    {
        $this->refuseWhileBusy($image);
        $changes = [];
        if (array_key_exists('why', $input)) {
            $changes['why'] = $this->validWhy($input['why']);
        }
        $touchesBuild = array_key_exists('dockerfile', $input) || array_key_exists('build_args', $input);
        if ($touchesBuild && ! $image->isBuilt()) {
            throw ProblemException::one('dockerfile', self::BUILD_ONLY, "{$image->ref} is a {$image->source} image; only a build image has a Dockerfile and build arguments.");
        }
        if ($touchesBuild) {
            $changes += $this->validBuild(OperatorImage::SOURCE_BUILD, [
                'dockerfile' => $input['dockerfile'] ?? $image->dockerfile,
                'build_args' => array_key_exists('build_args', $input) ? $input['build_args'] : $image->build_args,
            ]);
        }

        $rebuild = $touchesBuild
            && ($changes['dockerfile'] !== $image->dockerfile || $changes['build_args'] !== $image->build_args);
        $image->fill($changes)->save();

        return $rebuild;
    }

    /**
     * {@see update()}, then queue the rebuild a changed build needs; the
     * image's task_id is that task. False when nothing has to be rebuilt.
     *
     * @param  array<string, mixed>  $input  dockerfile, build_args, why
     *
     * @throws ProblemException
     */
    public function updateQueued(OperatorImage $image, array $input): bool
    {
        if (! $this->update($image, $input)) {
            return false;
        }
        $this->queue($image, ApplyOperatorImage::PUBLISH);

        return true;
    }

    /**
     * Get the image onto the host and into the cache registry, and record what
     * the registry then holds. $fresh rebuilds or re-pulls from upstream.
     *
     * @param  callable(string): void  $output
     */
    public function publish(OperatorImage $image, bool $fresh, callable $output): void
    {
        $this->attempt($image, OperatorImage::STATUS_PENDING, function () use ($image, $fresh, $output): void {
            $this->host->acquire($image, $fresh, $output);
            $this->host->publish($image->ref, $output);
            $measured = $this->host->measure($image->ref);
            if ($measured['digest'] === null) {
                throw new RuntimeException("The push finished, but the cache registry does not list {$image->ref}.");
            }
            $image->fill([
                'status' => OperatorImage::STATUS_READY,
                'digest' => $measured['digest'],
                'size_bytes' => $measured['size'],
                'refreshed_at' => now(),
                'error' => null,
            ])->save();
        });
    }

    /**
     * Renew the image the way its source says. A local image whose host copy
     * is gone keeps what the registry holds: there is nothing to renew it from.
     *
     * @param  callable(string): void  $output
     * @return bool whether anything was renewed
     */
    public function refresh(OperatorImage $image, callable $output): bool
    {
        if ($image->source === OperatorImage::SOURCE_LOCAL && ! $this->host->isOnHost($image->ref)) {
            return false;
        }
        $this->publish($image, true, $output);

        return true;
    }

    /**
     * The weekly refresh: renew every image no operation is changing, one at a
     * time, a failure keeping what the registry holds. False when the images cannot be read.
     *
     * @param  callable(OperatorImage, string, ?string): void  $report  each image, a REFRESH_* step, and why it failed
     */
    public function refreshAll(callable $report): bool
    {
        try {
            $images = OperatorImage::query()->orderBy('ref')->get();
        } catch (\Throwable) {
            return false;
        }
        foreach ($images as $image) {
            $this->refreshListed($image, $report);
        }

        return true;
    }

    /**
     * Take the image out of the cache registry, garbage-collect, drop the
     * host copies the engine made, then forget it.
     *
     * @return string what happened to the registry's copy
     */
    public function remove(OperatorImage $image): string
    {
        $outcome = '';
        $this->attempt($image, OperatorImage::STATUS_REMOVING, function () use ($image, &$outcome): void {
            $outcome = $this->host->unpublish($image->ref);
            $this->host->dropHostCopies($image);
            $image->delete();
        });

        return $outcome;
    }

    /**
     * Queue $action as a task the caller can follow.
     *
     * @throws ProblemException when an earlier operation on it is still running
     */
    public function queue(OperatorImage $image, string $action): Task
    {
        $this->refuseWhileBusy($image);
        $task = Task::start(
            jobType: ApplyOperatorImage::class,
            queue: 'default',
            details: ['action' => 'operator_image_'.$action, 'image' => $image->ref, 'image_id' => $image->id],
        );
        $image->fill(['task_id' => $task->id, 'status' => self::statusDuring($action)])->save();
        ApplyOperatorImage::dispatch($image->id, $action)->attachTask($task);

        return $task;
    }

    /** A queued $action whose job died before it could record how it ended: the image it left mid-way is failed. */
    public static function abandon(int $id, string $action, string $error): void
    {
        $image = OperatorImage::query()->find($id);
        if ($image !== null && $image->status === self::statusDuring($action)) {
            self::recordFailure($image, $error);
        }
    }

    /**
     * @throws ProblemException
     */
    public function refuseWhileBusy(OperatorImage $image): void
    {
        if ($image->isBusy()) {
            throw ProblemException::conflict('ref', self::BUSY, "{$image->ref} is still being worked on by task {$image->task_id}; wait for it to finish.");
        }
    }

    /**
     * @param  callable(OperatorImage, string, ?string): void  $report
     */
    private function refreshListed(OperatorImage $image, callable $report): void
    {
        if ($image->isBusy() || $image->status === OperatorImage::STATUS_REMOVING) {
            $report($image, self::REFRESH_SKIPPED, null);

            return;
        }
        $report($image, self::REFRESH_STARTED, null);
        try {
            $renewed = $this->refresh($image, static function (string $chunk): void {});
        } catch (\Throwable $e) {
            $report($image, self::REFRESH_FAILED, $e->getMessage());

            return;
        }
        $report($image, $renewed ? self::REFRESH_RENEWED : self::REFRESH_KEPT, null);
    }

    /**
     * @param  callable(): void  $work
     */
    private function attempt(OperatorImage $image, string $status, callable $work): void
    {
        $image->fill(['status' => $status, 'error' => null])->save();
        try {
            $work();
        } catch (\Throwable $e) {
            self::recordFailure($image, $e->getMessage());
            throw $e;
        }
    }

    private static function recordFailure(OperatorImage $image, string $error): void
    {
        $image->fill(['status' => OperatorImage::STATUS_FAILED, 'error' => Str::limit($error, self::ERROR_MAX)])->save();
    }

    private static function statusDuring(string $action): string
    {
        return $action === ApplyOperatorImage::REMOVE ? OperatorImage::STATUS_REMOVING : OperatorImage::STATUS_PENDING;
    }

    private function validSource(mixed $source): string
    {
        if (! is_string($source) || ! in_array($source, OperatorImage::SOURCES, true)) {
            throw ProblemException::one('source', self::SOURCE_INVALID, 'source is one of: '.implode(', ', OperatorImage::SOURCES).'.');
        }

        return $source;
    }

    private function validateRef(string $ref, string $source): void
    {
        $problem = OperatorImageRef::problem($ref, $source);
        if ($problem !== null) {
            throw ProblemException::one('ref', $problem['code'], $problem['message']);
        }
    }

    private function refuseDockerHubName(string $ref): void
    {
        $published = $this->lookup->onDockerHub($ref);
        if ($published === true) {
            throw ProblemException::one('ref', self::REF_PUBLIC, "Docker Hub publishes {$ref}, and every project here would get yours instead: pick a name Docker Hub does not use, or add it with source pull.");
        }
        if ($published === null) {
            throw ProblemException::one('ref', self::REF_UNVERIFIED, "Could not ask Docker Hub through panelalpha-registry-proxy whether it publishes {$ref}. Check that registry-proxy is running, then try again.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{dockerfile: ?string, build_args: ?array<string, string>}
     */
    private function validBuild(string $source, array $input): array
    {
        $dockerfile = $input['dockerfile'] ?? null;
        $args = $input['build_args'] ?? null;
        if ($source !== OperatorImage::SOURCE_BUILD) {
            if ($dockerfile !== null || ($args !== null && $args !== [])) {
                throw ProblemException::one('dockerfile', self::BUILD_ONLY, "A {$source} image has no Dockerfile or build arguments.");
            }

            return ['dockerfile' => null, 'build_args' => null];
        }

        return ['dockerfile' => $this->validDockerfile($dockerfile), 'build_args' => $this->validBuildArgs($args)];
    }

    private function validDockerfile(mixed $dockerfile): string
    {
        if (! is_string($dockerfile) || trim($dockerfile) === '') {
            throw ProblemException::one('dockerfile', self::DOCKERFILE_REQUIRED, 'A build image needs the Dockerfile text.');
        }
        if (strlen($dockerfile) > self::DOCKERFILE_MAX_BYTES || preg_match('/^\s*FROM\s/mi', $dockerfile) !== 1) {
            throw ProblemException::one('dockerfile', self::DOCKERFILE_INVALID, 'The Dockerfile must have a FROM line and stay under '.(self::DOCKERFILE_MAX_BYTES / 1024).' KB.');
        }

        return $dockerfile;
    }

    /**
     * @return array<string, string>|null
     */
    private function validBuildArgs(mixed $args): ?array
    {
        if ($args === null || $args === []) {
            return null;
        }
        if (! is_array($args) || array_is_list($args) || count($args) > self::BUILD_ARGS_MAX) {
            throw ProblemException::one('build_args', self::BUILD_ARGS_INVALID, 'build_args is an object of up to '.self::BUILD_ARGS_MAX.' NAME: value pairs.');
        }
        $valid = [];
        foreach ($args as $name => $value) {
            if (! is_string($name) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1
                || ! is_scalar($value) || strlen((string) $value) > self::BUILD_ARG_VALUE_MAX) {
                throw ProblemException::one('build_args', self::BUILD_ARGS_INVALID, "Build argument '{$name}' needs a name of letters, digits and underscores and a value under ".self::BUILD_ARG_VALUE_MAX.' characters.');
            }
            $valid[$name] = (string) $value;
        }

        return $valid;
    }

    private function validWhy(mixed $why): ?string
    {
        if ($why === null || $why === '') {
            return null;
        }
        if (! is_string($why) || strlen($why) > self::WHY_MAX) {
            throw ProblemException::one('why', self::WHY_INVALID, 'why is a note of up to '.self::WHY_MAX.' characters.');
        }

        return $why;
    }
}
