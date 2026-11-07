<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Dind\DindHostBuilder;
use App\System\Privileged;
use App\System\Privileged\RootScripts;

/**
 * Argv that fill a {@see BuildCache}: untrusted RUN steps build in a throwaway sysbox BuildKit on {@see BuildNetwork},
 * and a separate container that runs no repository code publishes the export to the loopback-only writer.
 * Argv only, like {@see DindHostBuilder}; root runs them through {@see RootScripts::publishBuildCache()}.
 */
final class BuildCacheBuilder
{
    /** The file name the account's Railpack build reads its plan from. */
    public const RAILPACK_PLAN = 'railpack-plan-tmp.json';

    public function __construct(
        private readonly ?string $network,
        private readonly string $memory = '4g',
        private readonly int $pids = 4096,
    ) {}

    /**
     * A shallow clone of one branch, or of the default branch without one.
     *
     * @return list<string>
     */
    public function cloneArgv(string $repository, ?string $branch, string $into): array
    {
        $branch = trim((string) $branch);

        return [
            'git', '-c', 'core.hooksPath=/dev/null', 'clone', '--quiet', '--depth', '1', '--single-branch',
            ...($branch === '' ? [] : ['--branch', $branch]),
            '--', $repository, $into,
        ];
    }

    /**
     * The branch a clone checked out, which names the cache: a deploy records
     * the same one before it builds.
     *
     * @return list<string>
     */
    public function headBranchArgv(string $source): array
    {
        return ['git', '-C', $source, 'symbolic-ref', '--short', 'HEAD'];
    }

    /**
     * `railpack prepare .` in the checkout, as the account runs it, writing
     * the plan to $planDir under {@see RAILPACK_PLAN}.
     *
     * @return list<string>
     */
    public function railpackPrepareArgv(string $accountImage, string $source, string $planDir): array
    {
        return Privileged::buildCache()->railpackPrepare($this->network, $this->memory, $this->pids, $accountImage, $source, $planDir);
    }

    /**
     * Build one Dockerfile of $source in its own sysbox BuildKit, exporting only the layers to $out as an OCI layout.
     * $layouts maps each operator image the build starts `FROM` to its layout dir and digest ({@see exportLayoutArgv()}).
     *
     * @param  array{context: string, dockerfile: string, target: ?string, args: array<string, string>}  $build
     * @param  array<string, array{dir: string, digest: string}>  $layouts
     * @return list<string>
     */
    public function buildArgv(string $source, string $out, array $build, array $layouts = []): array
    {
        $context = $build['context'] === '' ? '/src' : '/src/'.$build['context'];
        $mounts = ['/src' => $source];
        $opts = ['--opt', 'filename='.$build['dockerfile']];
        if ($build['target'] !== null) {
            array_push($opts, '--opt', 'target='.$build['target']);
        }
        foreach ($build['args'] as $name => $value) {
            array_push($opts, '--opt', "build-arg:{$name}={$value}");
        }
        $index = 0;
        foreach ($layouts as $ref => $layout) {
            $store = 'base'.$index++;
            $mounts["/layouts/{$store}"] = $layout['dir'];
            array_push(
                $opts,
                '--oci-layout', "{$store}=/layouts/{$store}",
                '--opt', "context:{$ref}=oci-layout://{$store}:".self::layoutTag($ref).'@'.$layout['digest']
            );
        }

        return $this->buildctl($mounts, $out, $context, $context, $opts);
    }

    /**
     * The account's Railpack build, named as `docker buildx build --build-arg BUILDKIT_SYNTAX=… -f plan .` names it.
     *
     * @return list<string>
     */
    public function railpackBuildArgv(string $source, string $planDir, string $out): array
    {
        return $this->buildctl(
            ['/src' => $source, '/plan' => $planDir],
            $out,
            '/src',
            '/plan',
            ['--opt', 'filename='.self::RAILPACK_PLAN, '--opt', 'build-arg:BUILDKIT_SYNTAX='.RailpackCache::FRONTEND_IMAGE]
        );
    }

    /**
     * Copy an operator image out of the cache registry into $dir as an OCI
     * layout, for a build that starts `FROM` it. No repository code runs.
     *
     * @return list<string>
     */
    public function exportLayoutArgv(string $ref, string $dir): array
    {
        return Privileged::buildCache()->copyImageToLayout(self::registry(), BuildCache::hostRef($ref), $dir, self::layoutTag($ref));
    }

    /**
     * The digest an OCI layout's `index.json` records for $tag, or null when
     * the layout holds no such tag.
     */
    public static function layoutDigest(string $indexJson, string $tag): ?string
    {
        $index = json_decode($indexJson, true);
        foreach (is_array($index['manifests'] ?? null) ? $index['manifests'] : [] as $manifest) {
            if (is_array($manifest) && ($manifest['annotations']['org.opencontainers.image.ref.name'] ?? null) === $tag
                && is_string($manifest['digest'] ?? null)
            ) {
                return $manifest['digest'];
            }
        }

        return null;
    }

    /** A tag an OCI layout can hold for $ref: its own, `latest` when it has none. */
    public static function layoutTag(string $ref): string
    {
        $slash = strrpos($ref, '/');
        $colon = strrpos($ref, ':');

        return $colon !== false && ($slash === false || $colon > $slash)
            ? (string) preg_replace('/[^A-Za-z0-9_.-]/', '-', substr($ref, $colon + 1))
            : 'latest';
    }

    /**
     * @param  array<string, string>  $mounts  container path => host path, read-only
     * @param  list<string>  $opts
     * @return list<string>
     */
    private function buildctl(array $mounts, string $out, string $context, string $dockerfileDir, array $opts): array
    {
        return Privileged::buildCache()->buildKit($this->network, $this->memory, $this->pids, $mounts, ['/out' => $out], [
            'build', '--progress=plain',
            '--frontend', 'dockerfile.v0',
            '--local', 'context='.$context, '--local', 'dockerfile='.$dockerfileDir,
            ...$opts,
            '--export-cache', 'type=local,dest=/out,mode=max,oci-mediatypes=true,image-manifest=true',
        ]);
    }

    /** The writer's address, as {@see BuildCache::hostRef()} names it. */
    private static function registry(): string
    {
        return explode('/', BuildCache::hostRef('x'), 2)[0];
    }
}
