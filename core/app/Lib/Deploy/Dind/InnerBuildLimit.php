<?php

namespace App\Lib\Deploy\Dind;

use App\Lib\Deploy\Template\Template;

/**
 * An image build in an account's own Docker daemon, held to the host build
 * ceiling and made the first thing the kernel kills when memory runs out.
 *
 * moby's embedded BuildKit runs each RUN step of a Dockerfile, compose or
 * Railpack (`docker buildx build`) build in a cgroup of its own below
 * {@see CGROUP}, as a child of dockerd and so at dockerd's -500 OOM score.
 * Only the account's own limit bounded a build, and the app was killed first.
 * Builds argv only; InnerBuildGuard runs it in the account.
 */
final class InnerBuildLimit
{
    /** Where the account's dockerd (cgroupfs driver, no cgroup-parent) puts BuildKit's steps. */
    public const CGROUP = '/sys/fs/cgroup/docker/buildkit';

    /** Holds the token of the build being guarded; the guard stops when it changes or goes. */
    public const GUARD_FILE = '/run/panelalpha-build-guard';

    /** As a host build container's `--oom-score-adj`. */
    public const OOM_SCORE_ADJ = 1000;

    /** Longer than any build the engine waits for, so a guard whose deploy died still ends. */
    public const GUARD_SECONDS = 7200;

    /** The host build ceiling, never above the project's own limit; 0 when neither is known. */
    public static function megabytes(int $buildCeilingMb, int $projectMb): int
    {
        $known = array_filter([$buildCeilingMb, $projectMb], static fn (int $mb): bool => $mb > 0);

        return $known === [] ? 0 : min($known);
    }

    /**
     * Run as root in the account before a build: caps {@see CGROUP} and starts
     * the guard that raises the build's processes to {@see OOM_SCORE_ADJ}.
     *
     * @return list<string>
     */
    public static function applyCommand(int $megabytes, string $token): array
    {
        return [
            'sh', '-c', self::script('build-guard'), 'build-guard',
            self::CGROUP,
            self::memoryMax($megabytes),
            self::GUARD_FILE,
            $token,
            (string) self::GUARD_SECONDS,
            (string) self::OOM_SCORE_ADJ,
        ];
    }

    /**
     * Run as root in the account after the build: stops the guard $token
     * started. The cap stays, so the next build is held to it as well.
     *
     * @return list<string>
     */
    public static function releaseCommand(string $token): array
    {
        return ['sh', '-c', self::script('build-guard-release'), 'build-guard-release', self::GUARD_FILE, $token];
    }

    private static function memoryMax(int $megabytes): string
    {
        return $megabytes > 0 ? (string) ($megabytes * 1024 * 1024) : 'max';
    }

    private static function script(string $name): string
    {
        return rtrim(Template::named('script/'.$name)->render([]), "\n");
    }
}
