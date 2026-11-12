<?php

namespace App\System\Privileged\Scripts;

use App\Lib\Deploy\CacheManager\PublishedBaseImage;
use App\Lib\Deploy\Dind\DindImageStore;
use App\System\Privileged\Arguments;
use App\System\Privileged\Root;
use App\System\Privileged\RootAccountDockerLines;
use App\System\Privileged\RootDockerLines;

/** {@see DindImageStore}'s host image pipelines. They run without root, and every root command is a broker line. */
final class ImagePipelines
{
    /** Runs a command holding the cache registry's push lock shared. */
    private const HOLDING_PUSH_LOCK = '( touch %s 2>/dev/null; exec 9<%s && flock -s 9 && { %s; } )';

    /** Tries $published first. */
    public function hostBuild(string $tag, string $dockerfile, ?string $published, bool $rebuild, bool $pull): string
    {
        $docker = new RootDockerLines;
        $stdin = "printf '%s' ".Root::shellWord($dockerfile).' | ';
        $build = $stdin.$docker->buildFromStdin($tag, $pull);
        if ($published !== null) {
            $build = "{ {$this->hostPullPublished($tag, $published, $dockerfile)}; } || { {$build}; }";
        }

        return $rebuild ? $build : "{$docker->inspectImage($tag)} >/dev/null 2>&1 || { {$build}; }";
    }

    /** Tags $published as $tag only when its label holds the hash of $dockerfile. */
    public function hostPullPublished(string $tag, string $published, string $dockerfile): string
    {
        $docker = new RootDockerLines;
        $img = Root::shellWord(Arguments::image($tag));
        $pub = Root::shellWord(Arguments::image($published));
        $sha = Root::shellWord(PublishedBaseImage::dockerfileSha($dockerfile));
        $label = $docker->imageLabel($published, PublishedBaseImage::DOCKERFILE_LABEL);

        return "{$docker->pullQuietly($published)} >/dev/null 2>&1"
            ." && { if [ \"\$({$label} 2>/dev/null)\" = {$sha} ];"
            ." then {$docker->tag($published, $tag)} && printf 'Pulled base image %s from %s\\n' {$img} {$pub}; s=\$?;"
            ." else printf 'Not using %s: it was built from a different Dockerfile\\n' {$pub} >&2; s=1; fi;"
            ." {$docker->removeImage($published)} >/dev/null 2>&1; [ \$s -eq 0 ]; }";
    }

    /** Holds the push lock shared. */
    public function hostPublish(string $image): string
    {
        $docker = new RootDockerLines;
        $pushRef = DindImageStore::HOST_CACHE_REGISTRY.'/'.$image;

        return $this->holdingPushLock("{$docker->tag($image, $pushRef)} && {$docker->pushQuietly($pushRef)} >/dev/null");
    }

    /** Skips the build when a concurrent build already made $image. Only the push holds the push lock. */
    public function hostRebuild(string $image, string $dockerfile, ?string $published): string
    {
        $has = (new RootDockerLines)->inspectImage($image).' >/dev/null 2>&1';
        $lock = Root::shellWord(DindImageStore::buildLockPath($image));

        return "( touch {$lock} 2>/dev/null; exec 8<{$lock} && flock -x 8"
            ." && if ! {$has}; then { {$this->hostBuild($image, $dockerfile, $published, false, true)}; }"
            ." && {$this->hostPublish($image)}; fi )";
    }

    /** Pushes from the host and pulls in $username's account through the cache registry, with one retry. */
    public function loadFromHost(string $username, string $image): string
    {
        $docker = new RootDockerLines;
        $pushRef = DindImageStore::HOST_CACHE_REGISTRY.'/'.Arguments::image($image);
        $pullRef = DindImageStore::CACHE_REGISTRY.'/'.$image;
        $account = new RootAccountDockerLines($username);

        $attempt = $docker->tag($image, $pushRef)
            ." && {$docker->pushQuietly($pushRef)} >/dev/null"
            .' && '.$account->pull($pullRef).' >/dev/null'
            .' && '.$account->tag($pullRef, $image)
            .' && { '.$account->removeImage($pullRef).' >/dev/null 2>&1 || true; }';

        return "{ {$this->bothRegistriesRunning()} || { echo ".Root::shellWord(DindImageStore::REGISTRY_DOWN).' >&2; false; }; }'
            .' && '.$this->holdingPushLock("{ {$attempt}; } || { sleep 2; {$attempt}; }");
    }

    /** A garbage-collect holds {@see DindImageStore::PUSH_LOCK} exclusive. */
    private function holdingPushLock(string $command): string
    {
        $lock = Root::shellWord(DindImageStore::pushLock());

        return sprintf(self::HOLDING_PUSH_LOCK, $lock, $lock, $command);
    }

    private function bothRegistriesRunning(): string
    {
        return $this->registryRunning(DindImageStore::CACHE_REGISTRY_CONTAINER)
            .' && '.$this->registryRunning(DindImageStore::CACHE_REGISTRY_WRITER_CONTAINER);
    }

    private function registryRunning(string $container): string
    {
        return (new RootDockerLines)->isRunning($container).' 2>/dev/null | grep -qx true';
    }
}
