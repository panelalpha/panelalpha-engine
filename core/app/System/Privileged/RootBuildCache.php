<?php

namespace App\System\Privileged;

/** Containers on the host's daemon that fill a repository's build cache. */
final class RootBuildCache
{
    private const AREA = 'build-cache';

    private const MEMORY = '/\A[0-9]+[bkmgBKMG]?\z/';

    private const REGISTRY = '/\A[A-Za-z0-9.-]+:[0-9]{1,5}\z/';

    private const TAG = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}\z/';

    /**
     * @param  array<string, string>  $readOnlyMounts  container path => host path
     * @param  array<string, string>  $writableMounts  container path => host path
     * @param  array<int, string>  $buildctlArguments
     * @return list<string>
     */
    public function buildKit(?string $network, string $memory, int $pids, array $readOnlyMounts, array $writableMounts, array $buildctlArguments): array
    {
        return $this->docker(__FUNCTION__, [
            ...$this->sandboxValues($network, $memory, $pids),
            ...$this->mountValues($readOnlyMounts),
            ...$this->mountValues($writableMounts),
            ...array_values($buildctlArguments),
        ]);
    }

    /** @return list<string> */
    public function railpackPrepare(?string $network, string $memory, int $pids, string $accountImage, string $source, string $planDir): array
    {
        return $this->docker(__FUNCTION__, [
            ...$this->sandboxValues($network, $memory, $pids),
            Arguments::image($accountImage),
            Arguments::mountPath($source),
            Arguments::mountPath($planDir),
        ]);
    }

    /** @return list<string> */
    public function copyImageToLayout(string $registry, string $ref, string $dir, string $tag): array
    {
        return $this->docker(__FUNCTION__, [$this->registry($registry), Arguments::image($ref), Arguments::mountPath($dir), $this->tag($tag)]);
    }

    /**
     * Network ('' for Docker's default), memory and pids limit.
     *
     * @return list<string>
     */
    private function sandboxValues(?string $network, string $memory, int $pids): array
    {
        if (preg_match(self::MEMORY, $memory) !== 1) {
            throw InvalidArgument::of('memory limit', $memory);
        }
        if ($pids < 1) {
            throw InvalidArgument::of('pids limit', (string) $pids);
        }

        return [$network === null ? '' : Arguments::name($network), $memory, (string) $pids];
    }

    /**
     * @param  array<string, string>  $mounts  container path => host path
     * @return list<string>
     */
    private function mountValues(array $mounts): array
    {
        foreach ($mounts as $container => $host) {
            Arguments::mountPath($host);
            Arguments::mountPath((string) $container);
        }

        return ActionValues::pairs($mounts);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function docker(string $action, array $values): array
    {
        return Root::action(self::AREA, $action, $values);
    }

    private function registry(string $registry): string
    {
        if (preg_match(self::REGISTRY, $registry) !== 1 || str_starts_with($registry, '-')) {
            throw InvalidArgument::of('registry', $registry);
        }

        return $registry;
    }

    private function tag(string $tag): string
    {
        if (preg_match(self::TAG, $tag) !== 1) {
            throw InvalidArgument::of('tag', $tag);
        }

        return $tag;
    }
}
