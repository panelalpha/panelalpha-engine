<?php

namespace App\Lib\Deploy\Compose;

use App\Lib\Deploy\AccountHomeRead;

/**
 * Where a repository shows it builds a tag its compose file only runs: another compose file that builds it, a
 * `docker build -t` in its README, Makefile or scripts, a Dockerfile named after it, or the one Dockerfile beside
 * the compose file. Each answer is one build or none: two different builds for one tag are never chosen between.
 */
final class LocalImageEvidence
{
    /** Directories that hold dependencies or caches, not the repository's own build. */
    private const SKIPPED_DIRS = ['.git', 'node_modules', 'vendor', '.venv', 'venv', '__pycache__', '.next', '.cache'];

    private const MAX_DEPTH = 4;

    private const MAX_FILES = 20000;

    private const DOC_DEPTH = 3;

    private const MAX_DOC_BYTES = 262144;

    private string $root;

    /** @var list<string>|null project-relative paths */
    private ?array $files = null;

    /**
     * @param  string  $composeDir  the run compose file's own directory, project-relative ('' at the root)
     * @param  list<string>  $ownFiles  project-relative compose files that are the run file itself or its sources
     * @param  array<string, list<?string>|string>  $env
     */
    public function __construct(
        string $projectDir,
        private readonly string $composeDir = '',
        private readonly array $ownFiles = [],
        private readonly array $env = []
    ) {
        $this->root = rtrim($projectDir, '/');
    }

    /**
     * The build another compose file, a build command or a Dockerfile's name gives $image.
     *
     * @param  list<string>  $services  the services that run it
     * @return array{build: array<string, mixed>, dockerfile: string, evidence: string}|null
     */
    public function explicitBuild(string $image, array $services): ?array
    {
        return $this->fromOtherComposeFiles($image)
            ?? $this->fromBuildCommands($image)
            ?? $this->fromNamedDockerfile($image, $services);
    }

    /**
     * The only Dockerfile at the root and beside the compose file that no service builds already.
     *
     * @param  list<string>  $built  absolute Dockerfiles already built
     * @return array{build: array<string, mixed>, dockerfile: string, evidence: string}|null
     */
    public function soleDockerfile(array $built): ?array
    {
        $dirs = array_values(array_unique(['', trim($this->composeDir, '/')]));
        $found = [];
        foreach ($this->listing() as $path) {
            if (in_array(self::dirOf($path), $dirs, true) && self::isDockerfileName(basename($path))
                && ! in_array(self::canonical($this->root.'/'.$path), $built, true)
            ) {
                $found[] = $path;
            }
        }
        if (count($found) !== 1) {
            return null;
        }
        $candidate = $this->withContextFromCopies($found[0]);

        return $candidate === null ? null : $candidate + ['evidence' => "the only Dockerfile beside the compose file, {$found[0]}"];
    }

    /**
     * The absolute Dockerfile each `build:` of $services reads.
     *
     * @param  array<mixed>  $services
     * @return list<string>
     */
    public function dockerfilesBuiltBy(array $services): array
    {
        $paths = [];
        foreach ($services as $service) {
            $build = is_array($service) ? ($service['build'] ?? null) : null;
            $path = is_array($build) || is_string($build) ? ComposeFileInspector::composeBuildDockerfileAbsolute($this->root, $build) : null;
            if ($path !== null) {
                $paths[] = self::canonical($path);
            }
        }

        return $paths;
    }

    /** @return array{build: array<string, mixed>, dockerfile: string, evidence: string}|null */
    private function fromOtherComposeFiles(string $image): ?array
    {
        $found = [];
        foreach ($this->listing() as $path) {
            if (! self::isComposeName(basename($path)) || in_array($path, $this->ownFiles, true)) {
                continue;
            }
            foreach ($this->buildsIn($path, $image) as $build) {
                $found[] = $build + ['evidence' => "{$path} builds it"];
            }
        }

        return self::onlyOne($found);
    }

    /**
     * @return list<array{build: array<string, mixed>, dockerfile: string}>
     */
    private function buildsIn(string $path, string $image): array
    {
        $parsed = ComposeYaml::parse((string) AccountHomeRead::contents($this->root.'/'.$path));
        $services = is_array($parsed['services'] ?? null) ? $parsed['services'] : [];
        $builds = [];
        foreach ($services as $service) {
            if (! is_array($service) || ! isset($service['build'])
                || DeployCompose::resolvedImageRef($service['image'] ?? null, $this->env) !== $image
            ) {
                continue;
            }
            $rebased = NestedCompose::rebase(['services' => ['s' => ['build' => $service['build']]]], self::dirOf($path));
            $build = self::buildSpec($rebased['services']['s']['build']);
            $dockerfile = $build === null ? null : ComposeFileInspector::composeBuildDockerfileAbsolute($this->root, $build);
            if ($dockerfile !== null && is_file($dockerfile)) {
                $builds[] = ['build' => $build, 'dockerfile' => self::canonical($dockerfile)];
            }
        }

        return $builds;
    }

    /** @return array{build: array<string, mixed>, dockerfile: string, evidence: string}|null */
    private function fromBuildCommands(string $image): ?array
    {
        [$name, $tag] = self::nameAndTag($image);
        $found = [];
        foreach ($this->listing() as $path) {
            if (substr_count($path, '/') >= self::DOC_DEPTH || ! self::isBuildDocument(basename($path))) {
                continue;
            }
            $text = $this->readDocument($path);
            foreach ($text === null ? [] : DockerBuildCommand::buildsOf($text, $name, $tag) as $command) {
                $build = $this->commandBuild($command);
                if ($build !== null) {
                    $found[] = $build + ['evidence' => "{$path} builds it with `{$command['line']}`"];
                }
            }
        }

        return self::onlyOne($found);
    }

    /**
     * @param  array{context: string, dockerfile: ?string, target: ?string}  $command
     * @return array{build: array<string, mixed>, dockerfile: string}|null
     */
    private function commandBuild(array $command): ?array
    {
        $context = self::relative($command['context']);
        $dockerfile = $command['dockerfile'] === null
            ? self::joined($context, 'Dockerfile')
            : self::relative($command['dockerfile']);
        if (! is_dir($this->root.'/'.$context) || ! is_file($this->root.'/'.$dockerfile)) {
            return null;
        }
        $build = ['context' => self::composePath($context), 'dockerfile' => self::fromDir($context, $dockerfile)];
        if ($command['target'] !== null) {
            $build['target'] = $command['target'];
        }

        return ['build' => $build, 'dockerfile' => self::canonical($this->root.'/'.$dockerfile)];
    }

    /**
     * @param  list<string>  $services
     * @return array{build: array<string, mixed>, dockerfile: string, evidence: string}|null
     */
    private function fromNamedDockerfile(string $image, array $services): ?array
    {
        $names = array_values(array_unique(array_map('strtolower', [self::nameAndTag($image)[0], ...$services])));
        $names = array_map(static fn (string $name): string => basename($name), $names);
        $found = [];
        foreach ($this->listing() as $path) {
            if (self::isNamedAfter($path, $names)) {
                $found[] = $path;
            }
        }
        if (count($found) !== 1) {
            return null;
        }
        $candidate = $this->withContextFromCopies($found[0]);

        return $candidate === null ? null : $candidate + ['evidence' => "{$found[0]} is named after it"];
    }

    /** @param  list<string>  $names */
    private static function isNamedAfter(string $path, array $names): bool
    {
        $file = strtolower(basename($path));
        foreach ($names as $name) {
            if ($file === 'dockerfile.'.$name || $file === $name.'.dockerfile'
                || ($file === 'dockerfile' && strtolower(basename(self::dirOf($path))) === $name)
            ) {
                return true;
            }
        }

        return false;
    }

    /** @return array{build: array<string, mixed>, dockerfile: string}|null */
    private function withContextFromCopies(string $dockerfile): ?array
    {
        $absolute = $this->root.'/'.$dockerfile;
        $dir = self::dirOf($dockerfile);
        $context = DockerfileContext::of($absolute, array_values(array_unique([
            $dir === '' ? $this->root : $this->root.'/'.$dir,
            $this->root,
        ])));
        if ($context === null) {
            return null;
        }
        $relativeContext = $context === $this->root ? '' : substr($context, strlen($this->root) + 1);

        return [
            'build' => ['context' => self::composePath($relativeContext), 'dockerfile' => self::fromDir($relativeContext, $dockerfile)],
            'dockerfile' => self::canonical($absolute),
        ];
    }

    /**
     * @param  list<array{build: array<string, mixed>, dockerfile: string, evidence: string}>  $found
     * @return array{build: array<string, mixed>, dockerfile: string, evidence: string}|null
     */
    private static function onlyOne(array $found): ?array
    {
        $distinct = [];
        foreach ($found as $candidate) {
            $build = $candidate['build'];
            $key = $build['context']."\0".$candidate['dockerfile']."\0".($build['target'] ?? '');
            $distinct[$key] ??= $candidate;
        }

        return count($distinct) === 1 ? array_values($distinct)[0] : null;
    }

    /** @return array<string, mixed>|null the mapping form, keeping what selects the image: context, Dockerfile, target, args */
    private static function buildSpec(mixed $build): ?array
    {
        if (is_string($build)) {
            return ['context' => $build];
        }
        if (! is_array($build) || isset($build['dockerfile_inline'])) {
            return null;
        }

        return array_intersect_key($build, array_flip(['context', 'dockerfile', 'target', 'args']));
    }

    /**
     * The repository's files, bounded in depth and number, dependency directories left out.
     *
     * @return list<string>
     */
    private function listing(): array
    {
        if ($this->files === null) {
            $this->files = [];
            $this->walk('', 0);
        }

        return $this->files;
    }

    private function walk(string $relative, int $depth): void
    {
        $entries = @scandir($relative === '' ? $this->root : $this->root.'/'.$relative);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..' || count($this->files) >= self::MAX_FILES) {
                continue;
            }
            $path = $relative === '' ? $entry : $relative.'/'.$entry;
            $absolute = $this->root.'/'.$path;
            if (is_link($absolute)) {
                continue;
            }
            if (is_dir($absolute)) {
                if ($depth < self::MAX_DEPTH && ! in_array($entry, self::SKIPPED_DIRS, true)) {
                    $this->walk($path, $depth + 1);
                }

                continue;
            }
            $this->files[] = $path;
        }
    }

    private function readDocument(string $path): ?string
    {
        $absolute = $this->root.'/'.$path;
        $size = @filesize($absolute);
        if ($size === false || $size > self::MAX_DOC_BYTES) {
            return null;
        }
        $text = AccountHomeRead::contents($absolute);

        return is_string($text) && str_contains($text, 'docker') ? $text : null;
    }

    private static function isBuildDocument(string $file): bool
    {
        return preg_match('/^(readme.*|.*\.(md|markdown|rst|sh|mk)|makefile|gnumakefile|justfile|taskfile\.ya?ml|package\.json)$/i', $file) === 1;
    }

    private static function isComposeName(string $file): bool
    {
        return preg_match('/^(docker-)?compose([._-][^\/]*)?\.ya?ml$/i', $file) === 1;
    }

    private static function isDockerfileName(string $file): bool
    {
        return preg_match('/^dockerfile(\..+)?$|\.dockerfile$/i', $file) === 1;
    }

    /** @return array{0: string, 1: string} */
    private static function nameAndTag(string $image): array
    {
        $colon = strrpos($image, ':');
        $slash = strrpos($image, '/');
        if ($colon !== false && ($slash === false || $colon > $slash)) {
            return [substr($image, 0, $colon), substr($image, $colon + 1)];
        }

        return [$image, 'latest'];
    }

    private static function dirOf(string $path): string
    {
        $dir = dirname($path);

        return $dir === '.' ? '' : $dir;
    }

    /** A path as the repository root sees it, `./` and trailing slashes gone ('' for the root itself). */
    private static function relative(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function joined(string $dir, string $file): string
    {
        return $dir === '' ? $file : $dir.'/'.$file;
    }

    private static function composePath(string $relative): string
    {
        return $relative === '' ? '.' : './'.$relative;
    }

    /** $file, project-relative, as Compose names it from the context $dir: `dockerfile:` is relative to the context. */
    private static function fromDir(string $dir, string $file): string
    {
        if ($dir === '') {
            return $file;
        }
        if (str_starts_with($file, $dir.'/')) {
            return substr($file, strlen($dir) + 1);
        }

        return str_repeat('../', substr_count($dir, '/') + 1).$file;
    }

    private static function canonical(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }
}
