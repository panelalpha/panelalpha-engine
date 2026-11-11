<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\DindImageStore;
use App\Lib\Deploy\Engine\EngineFactory;
use App\Lib\Deploy\Engine\ImageStore;
use App\Models\OperatorImage;
use App\System;
use App\System\Privileged;
use RuntimeException;

/**
 * Fetches or builds an operator image on the host, and publishes it to or removes it from the cache registry.
 */
class OperatorImageHost
{
    public const BUILD_TIMEOUT_SECONDS = 3600;

    private const OUTPUT_TAIL_BYTES = 4000;

    public function __construct(
        private readonly System $system,
        private readonly ImageStore $store,
        private readonly CacheRegistry $registry,
        private readonly RegistryImageConfig $config,
    ) {}

    public static function make(): self
    {
        return new self(new System, EngineFactory::default()->images(), new CacheRegistry, new RegistryImageConfig);
    }

    /**
     * Pull it, build it, or check it is there. $fresh rebuilds every layer
     * from upstream, which is what a refresh is for.
     *
     * @param  callable(string): void  $output
     */
    public function acquire(OperatorImage $image, bool $fresh, callable $output): void
    {
        match ($image->source) {
            OperatorImage::SOURCE_PULL => $this->run(Privileged::docker()->pull($image->ref), $output, 1800),
            OperatorImage::SOURCE_BUILD => $this->run(
                self::buildCommand($image->ref, (string) $image->dockerfile, $image->buildArgs(), $fresh),
                $output,
                self::BUILD_TIMEOUT_SECONDS
            ),
            default => $this->requireOnHost($image->ref),
        };
    }

    /**
     * `docker build -` with no context, so nothing from the host lands in an image every project can pull.
     * Provenance and SBOM are off, or the result is an index an account cannot import.
     *
     * @param  array<string, string>  $buildArgs
     */
    public static function buildCommand(string $ref, string $dockerfile, array $buildArgs, bool $fresh): string
    {
        $argv = Privileged::docker()->buildFromStdin($ref, $buildArgs, $fresh);

        return 'printf %s '.escapeshellarg($dockerfile).' | '.implode(' ', array_map('escapeshellarg', $argv));
    }

    public function isOnHost(string $ref): bool
    {
        try {
            return trim($this->system->exec(Privileged::docker()->imageId($ref), [], 60)) !== '';
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @param  callable(string): void  $output
     */
    public function publish(string $ref, callable $output): void
    {
        $this->run($this->store->hostPublishCommand($ref), $output, 1800);
    }

    /**
     * What the cache registry holds under $ref: its digest, and the bytes a pull moves.
     *
     * @return array{digest: ?string, size: ?int}
     */
    public function measure(string $ref): array
    {
        $digest = $this->registry->digest($ref);

        return ['digest' => $digest, 'size' => $digest === null ? null : $this->config->cachedDownloadBytes($ref)];
    }

    /**
     * Take $ref out of the cache registry and garbage-collect. A tag sharing its digest with another tag of the
     * repository stays, since deleting the manifest would take the other tag with it.
     *
     * @return string what happened, for the operator
     */
    public function unpublish(string $ref): string
    {
        $outcome = 'not in the cache registry';
        $locked = CacheRegistryLock::exclusive(function () use ($ref, &$outcome): void {
            $outcome = $this->deleteTag($ref);
            $this->system->exec(DindImageStore::garbageCollectArgv(), [], 1800);
            $this->registry->removeEmptyRepositories($this->system);
        });
        if (! $locked) {
            throw new RuntimeException('Could not lock '.DindImageStore::pushLock().'; the cache registry was left as it is.');
        }

        return $outcome;
    }

    /** The host copies the engine made; a local image's own tag is the operator's and stays. */
    public function dropHostCopies(OperatorImage $image): void
    {
        $tags = [DindImageStore::HOST_CACHE_REGISTRY.'/'.$image->ref];
        if ($image->source !== OperatorImage::SOURCE_LOCAL) {
            $tags[] = $image->ref;
        }
        foreach ($tags as $tag) {
            try {
                $this->system->exec(Privileged::docker()->removeImages($tag), [], 120);
            } catch (\Exception) {
            }
        }
    }

    private function deleteTag(string $ref): string
    {
        $refs = $this->registry->refs();
        if ($refs === null) {
            throw new RuntimeException('Could not list the cache registry.');
        }
        if (! in_array($ref, $refs, true)) {
            return 'not in the cache registry';
        }

        $repository = HostImageRetention::repository($ref);
        $digests = [];
        foreach ($refs as $listed) {
            if (HostImageRetention::repository($listed) === $repository) {
                $digests[$listed] = $this->registry->digest($listed);
            }
        }
        $others = array_values(array_diff(array_keys($digests), [$ref]));
        if (! in_array($ref, CacheRegistry::unwanted($digests, $others), true)) {
            return 'kept in the cache registry: another tag of '.$repository.' has the same content';
        }

        return $this->registry->delete($ref, (string) $digests[$ref])
            ? 'removed from the cache registry'
            : 'the cache registry refused to remove it';
    }

    private function requireOnHost(string $ref): void
    {
        if (! $this->isOnHost($ref)) {
            throw new RuntimeException("{$ref} is not on this host. Build or load it with docker first.");
        }
    }

    /**
     * @param  string|list<string>  $command
     * @param  callable(string): void  $output
     */
    private function run(string|array $command, callable $output, int $timeout): void
    {
        $tail = '';
        $process = $this->system->runProcessWithCallbacks(
            $command,
            [],
            $timeout,
            null,
            static function (string $type, string $buffer) use ($output, &$tail): void {
                $output($buffer);
                $tail = substr($tail.$buffer, -self::OUTPUT_TAIL_BYTES);
            }
        );
        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($tail) !== '' ? trim($tail) : 'exit code '.$process->getExitCode());
        }
    }
}
