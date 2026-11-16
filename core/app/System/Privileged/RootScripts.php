<?php

namespace App\System\Privileged;

use App\System\Privileged\Scripts\ImagePipelines;

/**
 * Shell scripts with a root step. An image pipeline runs without root, and each of its root lines is a broker request.
 */
final class RootScripts
{
    private const AREA = 'scripts';

    private const FILE_NAME = '/\A[A-Za-z0-9_][A-Za-z0-9_.-]*\z/';

    private const DOCKER_STORAGE = 'docker';

    /**
     * The path is never read as shell.
     *
     * @return list<string>
     */
    public function removeStartingWith(string $path): array
    {
        return Root::action(self::AREA, __FUNCTION__, [Arguments::path($path)]);
    }

    /**
     * A symlink to a directory is not one.
     *
     * @return list<string>
     */
    public function isRealDirectory(string $path): array
    {
        return Root::action(self::AREA, __FUNCTION__, [Arguments::path($path)]);
    }

    /**
     * Makes or re-owns an entry directly in the home; never a symlink or `docker`.
     *
     * @return list<string>
     */
    public function makeHomeEntry(string $entry, string $mode, string $owner): array
    {
        if (basename($entry) === self::DOCKER_STORAGE) {
            throw InvalidArgument::of('home entry', $entry);
        }

        return Root::action(self::AREA, __FUNCTION__, [Arguments::path($entry), Arguments::mode($mode), Arguments::owner($owner)]);
    }

    /**
     * Every `image:` line in the other accounts' compose files, by grep, so a stray match is possible.
     *
     * @param  array<int, string>  $composeFileNames
     * @return list<string>
     */
    public function composeImageLines(string $homesDir, string $excludedUsername, array $composeFileNames): array
    {
        return Root::action(self::AREA, __FUNCTION__, [
            Arguments::path($homesDir),
            Arguments::username($excludedUsername),
            ...array_map($this->fileName(...), array_values($composeFileNames)),
        ]);
    }

    /**
     * Unless the host has $tag. $published is tried first.
     *
     * @return list<string>
     */
    public function buildImageOnHost(string $tag, string $dockerfile, ?string $published, bool $pull): array
    {
        return $this->pipeline((new ImagePipelines)->hostBuild($tag, $dockerfile, $published, false, $pull));
    }

    /**
     * Detached, so the deploy does not wait.
     *
     * @return list<string>
     */
    public function buildImageOnHostInBackground(string $tag, string $dockerfile, ?string $published): array
    {
        $build = (new ImagePipelines)->hostBuild($tag, $dockerfile, $published, false, true);

        return $this->pipeline('nohup sh -c '.Root::shellWord($build).' >/dev/null 2>&1 &');
    }

    /**
     * Builds under the image's build lock, then publishes to the cache registry.
     *
     * @return list<string>
     */
    public function rebuildImageOnHost(string $image, string $dockerfile, ?string $published): array
    {
        return $this->pipeline((new ImagePipelines)->hostRebuild($image, $dockerfile, $published));
    }

    /**
     * Into $username's account, through the cache registry.
     *
     * @return list<string>
     */
    public function loadImageFromHost(string $username, string $image): array
    {
        return $this->pipeline((new ImagePipelines)->loadFromHost($username, $image));
    }

    /**
     * Never during a garbage-collect.
     *
     * @return list<string>
     */
    public function publishBuildCache(string $layoutDir, string $hostRef): array
    {
        return Root::action(self::AREA, __FUNCTION__, [Arguments::mountPath($layoutDir), Arguments::image($hostRef)]);
    }

    private function fileName(string $name): string
    {
        if (preg_match(self::FILE_NAME, $name) !== 1) {
            throw InvalidArgument::of('file name', $name);
        }

        return $name;
    }

    /** @return list<string> */
    private function pipeline(string $script): array
    {
        return ['sh', '-c', $script];
    }
}
