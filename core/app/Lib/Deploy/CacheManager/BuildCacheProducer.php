<?php

namespace App\Lib\Deploy\CacheManager;

use App\Lib\Deploy\AccountHomeRead;
use App\Lib\Deploy\Compose\ComposeBuilds;
use App\Lib\Deploy\Compose\ComposeExtends;
use App\Lib\Deploy\Compose\ComposeInclude;
use App\Lib\Deploy\Compose\ComposeYaml;
use App\Lib\Deploy\Compose\NestedCompose;
use App\Lib\Deploy\DetectProjectStrategy;
use App\Lib\Deploy\Dind\BuildNetwork;
use App\Lib\Deploy\Platform\AppConfig\AppConfig;
use App\Lib\Deploy\Platform\Dockerfile\BuildContextIgnore;
use App\Lib\Deploy\Platform\Dockerfile\DockerIgnore;
use App\Lib\Deploy\Platform\Dockerfile\GitHistoryUse;
use App\Lib\Deploy\Platform\Strategies;
use App\Models\OperatorImage;
use App\System;
use App\System\Privileged;
use App\System\Project\Dind\Strategy\DockerfileStrategy;
use App\System\Project\Dind\SystemAppConfigSource;

/**
 * Fill the cache registry with a public repository's BuildKit caches, so the next account to deploy it imports them.
 * Each build matches the deploy's detection, Dockerfile, args, target and dockerignore, so `COPY . .` hashes the same.
 */
final class BuildCacheProducer
{
    public const WORK_ROOT = '/opt/panelalpha/shared-hosting/data/build-cache';

    private const BUILD_TIMEOUT_SECONDS = 3600;

    private const PUBLISH_TIMEOUT_SECONDS = 1800;

    /** @var callable(string): void */
    private $output;

    /**
     * @param  callable(string): void|null  $output  each progress line as it happens
     */
    public function __construct(
        private readonly System $system,
        private readonly BuildCacheBuilder $builder,
        ?callable $output = null,
    ) {
        $this->output = $output ?? static function (string $line): void {};
    }

    /** @param  callable(string): void|null  $output */
    public static function make(?callable $output = null): self
    {
        return new self(new System, new BuildCacheBuilder(BuildNetwork::resolve((string) config('deploy.build_network'))), $output);
    }

    /**
     * Build and publish every cache a deploy of $repository on $branch (the default branch when null) imports.
     * The work root is www-data's, so only the containers and their output need root.
     *
     * @return list<string> the refs published
     *
     * @throws BuildCacheRefused when the repository can have no shared build cache
     */
    public function build(string $repository, ?string $branch): array
    {
        if (BuildCache::repositoryPath($repository) === null) {
            throw new BuildCacheRefused('Only a public https clone URL can have a shared build cache.');
        }
        $work = self::WORK_ROOT.'/'.bin2hex(random_bytes(6));
        try {
            $this->system->exec(['mkdir', '-p', '--', $work], [], 30);
            $this->system->exec($this->builder->cloneArgv($repository, $branch, $work.'/src'), [], 900);
            $branch ??= trim((string) $this->system->exec($this->builder->headBranchArgv($work.'/src'), [], 60)) ?: null;

            return AccountHomeRead::confinedTo($work.'/src', fn (): array => $this->buildAll($repository, $branch, $work));
        } finally {
            $this->removeWork($work);
        }
    }

    /** A removal that fails is said, so it never replaces what the build returned or threw. */
    private function removeWork(string $work): void
    {
        try {
            $this->system->exec(Privileged::files()->removeTree($work), [], 300);
        } catch (\Throwable $e) {
            $this->say("Could not remove {$work}: ".self::lastLines($e->getMessage()));
        }
    }

    /**
     * @return list<string>
     */
    private function buildAll(string $repository, ?string $branch, string $work): array
    {
        $source = $work.'/src';
        if (AppConfig::load(new SystemAppConfigSource($this->system), $source, $repository) !== null) {
            throw new BuildCacheRefused('This repository deploys through an app config (a shipped recipe or its own '
                .'panelalpha.yaml), which changes what a deploy builds; it has no build cache yet.');
        }
        $decision = DetectProjectStrategy::detect($source);
        $strategy = $decision['strategy'];

        if ($strategy === Strategies::RAILPACK) {
            $ref = $this->railpack($repository, $branch, $work);

            return $ref === null ? [] : [$ref];
        }

        $builds = match ($strategy) {
            Strategies::DOCKERFILE => [
                (string) ($decision['dockerfile'] ?? 'Dockerfile') => [
                    'context' => '',
                    'dockerfile' => (string) ($decision['dockerfile'] ?? 'Dockerfile'),
                    'target' => null,
                    'args' => DockerfileStrategy::declaredBuildArgs($decision),
                ],
            ],
            Strategies::COMPOSE => $this->composeBuilds($source, (string) ($decision['compose_path'] ?? '')),
            default => [],
        };
        if ($builds === []) {
            throw new BuildCacheRefused('Deploys of this repository build nothing a cache can hold (strategy: '.$strategy.').');
        }

        $published = [];
        foreach ($builds as $name => $build) {
            $identity = ComposeBuilds::identity($build['context'], $build['dockerfile'], $build['target']);
            $ref = $this->dockerfile($repository, $branch, $work, $identity, $build);
            if ($ref === null) {
                $this->say("No cache for {$name}.");

                continue;
            }
            $published[] = $ref;
        }

        return $published;
    }

    /**
     * @param  array{context: string, dockerfile: string, target: ?string, args: array<string, string>}  $build
     */
    private function dockerfile(string $repository, ?string $branch, string $work, string $identity, array $build): ?string
    {
        $contextDir = $this->contextDir($work.'/src', $build);
        if ($contextDir === null || ! $this->writeContextIgnore($contextDir, $build['dockerfile'])) {
            return null;
        }
        $layouts = $this->operatorLayouts($work, (string) self::read($contextDir.'/'.$build['dockerfile']));

        return $this->buildAndPublish(
            (string) BuildCache::ref($repository, $branch, $identity),
            $work,
            fn (string $out): array => $this->builder->buildArgv($work.'/src', $out, $build, $layouts)
        );
    }

    private function railpack(string $repository, ?string $branch, string $work): ?string
    {
        $image = $this->accountImage();
        if ($image === null) {
            throw new BuildCacheRefused('The account image that carries railpack could not be read from the account template.');
        }
        $this->system->exec(['mkdir', '-p', '--', $work.'/plan'], [], 30);
        try {
            $this->system->exec($this->builder->railpackPrepareArgv($image, $work.'/src', $work.'/plan'), [], 900);
        } catch (\Exception $e) {
            throw new BuildCacheRefused('railpack prepare found no way to build this repository: '.self::lastLines($e->getMessage()));
        }

        return $this->buildAndPublish(
            (string) BuildCache::ref($repository, $branch, BuildCache::RAILPACK),
            $work,
            fn (string $out): array => $this->builder->railpackBuildArgv($work.'/src', $work.'/plan', $out)
        );
    }

    /**
     * @param  callable(string): list<string>  $buildArgv  the build, given the directory it exports to
     */
    private function buildAndPublish(string $ref, string $work, callable $buildArgv): ?string
    {
        $out = $work.'/out-'.substr(sha1($ref), 0, 8);
        $this->system->exec(['mkdir', '-p', '--', $out], [], 30);
        $this->say("Building {$ref}");
        $started = microtime(true);
        try {
            $this->system->exec($buildArgv($out), [], self::BUILD_TIMEOUT_SECONDS);
        } catch (\Exception $e) {
            $this->say("The build for {$ref} failed: ".self::lastLines($e->getMessage()));

            return null;
        }
        $built = microtime(true);

        $this->system->exec(Privileged::scripts()->publishBuildCache($out, BuildCache::hostRef($ref)), [], self::PUBLISH_TIMEOUT_SECONDS);
        $this->say(sprintf('Published %s: build %.1fs, publish %.1fs', $ref, $built - $started, microtime(true) - $built));

        return $ref;
    }

    /**
     * The compose file's builds as a deploy runs them: the same file, its
     * relative paths rebased to the root, includes and `extends` merged.
     *
     * @return array<string, array{context: string, dockerfile: string, target: ?string, args: array<string, string>}>
     */
    private function composeBuilds(string $source, string $composePath): array
    {
        $path = str_starts_with($composePath, '/') ? $composePath : $source.'/'.ltrim($composePath, '/');
        $parsed = ComposeYaml::parseFile($path);
        if (! is_array($parsed)) {
            return [];
        }
        $read = static fn (string $relative): ?string => self::read($source.'/'.$relative);
        $nested = NestedCompose::relativeDir($path, $source);
        if ($nested !== null) {
            $parsed = NestedCompose::rebase($parsed, $nested);
        }
        if (array_key_exists('include', $parsed)) {
            $parsed = ComposeInclude::flatten($parsed, $read)['compose'];
        }

        return ComposeBuilds::of(ComposeExtends::resolve($parsed, $read));
    }

    /**
     * The operator images the Dockerfile starts `FROM`, each copied out of the
     * cache registry into its own OCI layout under $work.
     *
     * @return array<string, array{dir: string, digest: string}>
     */
    private function operatorLayouts(string $work, string $dockerfile): array
    {
        $operator = OperatorImage::refs() ?? [];
        $layouts = [];
        foreach (array_intersect(OperatorBaseImages::fromRefs($dockerfile), $operator) as $ref) {
            $dir = $work.'/layout-'.substr(sha1($ref), 0, 8);
            $this->system->exec(['mkdir', '-p', '--', $dir], [], 30);
            $this->system->exec($this->builder->exportLayoutArgv($ref, $dir), [], self::PUBLISH_TIMEOUT_SECONDS);
            $digest = BuildCacheBuilder::layoutDigest((string) @file_get_contents($dir.'/index.json'), BuildCacheBuilder::layoutTag($ref));
            if ($digest === null) {
                $this->say("Could not copy {$ref} out of the cache registry; the build cannot start FROM it.");

                continue;
            }
            $layouts[$ref] = ['dir' => $dir, 'digest' => $digest];
        }

        return $layouts;
    }

    /**
     * The `<Dockerfile>.dockerignore` a deploy of this checkout writes, so the
     * build context, and with it every key after `COPY . .`, is the deploy's.
     */
    private function writeContextIgnore(string $contextDir, string $dockerfile): bool
    {
        $read = static fn (string $file): ?string => self::read($contextDir.'/'.$file);
        $target = BuildContextIgnore::pathFor($dockerfile);
        if ($read($target) !== null) {
            return true;
        }

        $manifests = [];
        foreach (GitHistoryUse::manifestFiles() as $file) {
            $manifests[$file] = $read($file);
        }
        $reason = GitHistoryUse::reason($read($dockerfile), $read(DockerIgnore::FILENAME), $manifests, $read);
        if ($reason !== null) {
            $this->say("{$dockerfile} keeps .git in its context because {$reason}; no other checkout hashes the same.");

            return false;
        }

        $written = self::create(self::below($contextDir, $target), BuildContextIgnore::render(
            $read(DockerIgnore::FILENAME),
            $dockerfile,
            true,
            false,
            BuildContextIgnore::excludesEnv(null, $read($dockerfile))
        ));
        if (! $written) {
            $this->say("Could not write {$target} into the clone.");
        }

        return $written;
    }

    /**
     * The build's context directory, or null when it, its Dockerfile or an ignore file the build reads is a
     * link, outside the clone or not what the build expects there, so nothing is read or written through one.
     *
     * @param  array{context: string, dockerfile: string, target: ?string, args: array<string, string>}  $build
     */
    private function contextDir(string $source, array $build): ?string
    {
        $root = realpath($source);
        if ($root === false) {
            return null;
        }
        $contextDir = self::below($root, $build['context']);
        $expected = [
            $contextDir => is_dir(...),
            self::below($contextDir, $build['dockerfile']) => is_file(...),
            self::below($contextDir, DockerIgnore::FILENAME) => self::isFileOrAbsent(...),
            self::below($contextDir, BuildContextIgnore::pathFor($build['dockerfile'])) => self::isFileOrAbsent(...),
        ];
        foreach ($expected as $path => $isExpected) {
            if (! self::isClonesOwn($path) || ! $isExpected($path)) {
                $relative = $path === $root ? '.' : substr($path, strlen($root) + 1);
                $this->say("{$relative} is a symbolic link, missing or not the kind of file the build reads; nothing is read or written through it.");

                return null;
            }
        }

        return $contextDir;
    }

    /** $relative under $dir, without its empty and `.` segments. */
    private static function below(string $dir, string $relative): string
    {
        $parts = array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '' && $part !== '.');

        return implode('/', [$dir, ...$parts]);
    }

    /** No link and no `..` on $path, built from the clone's real path; one that does not exist yet is the clone's too. */
    private static function isClonesOwn(string $path): bool
    {
        clearstatcache(true);
        if (is_link($path)) {
            return false;
        }
        $existing = file_exists($path) ? $path : dirname($path);

        return realpath($existing) === $existing;
    }

    private static function isFileOrAbsent(string $path): bool
    {
        return ! file_exists($path) || is_file($path);
    }

    /** A file of the clone, never one a link carries out of it ({@see AccountHomeRead::confinedTo()}). */
    private static function read(string $path): ?string
    {
        $contents = AccountHomeRead::contents($path);

        return $contents === false ? null : $contents;
    }

    /** Written under a fresh name and renamed into place, so nothing already at $path is opened. */
    private static function create(string $path, string $contents): bool
    {
        $temporary = dirname($path).'/.panelalpha-'.bin2hex(random_bytes(8));
        $handle = @fopen($temporary, 'x');
        if ($handle === false) {
            return false;
        }
        $complete = @fwrite($handle, $contents) === strlen($contents);
        fclose($handle);
        if ($complete && @rename($temporary, $path)) {
            return true;
        }
        @unlink($temporary);

        return false;
    }

    /** The image the account template runs, which carries the railpack binary. */
    private function accountImage(): ?string
    {
        $dir = rtrim($this->system->projectFilesTemplateDirPath('dind'), '/');
        foreach ([$dir.'/project/docker-compose.yml.blade.php', $dir.'/docker-compose.yml.blade.php'] as $template) {
            if (is_file($template) && preg_match('/^\s*image:\s*(\S+)\s*$/m', (string) file_get_contents($template), $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    private function say(string $line): void
    {
        ($this->output)($line);
    }

    private static function lastLines(string $output): string
    {
        return implode(' | ', array_slice(array_filter(array_map('trim', explode("\n", $output))), -3));
    }
}
