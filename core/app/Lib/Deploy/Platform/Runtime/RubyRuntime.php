<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Ruby\Gemfile;

/**
 * Ruby, versioned by `.ruby-version`, then the repo's Dockerfile, then the Gemfile constraint's floor when violated.
 * A declared version outside `MINORS` is honoured: that list is what the host warms, not a whitelist.
 */
final class RubyRuntime implements Runtime
{
    /**
     * Minors the engine seeds into accounts, and the compiled-in fallback for
     * the catalogue's `versions`. A prewarm hint, not a whitelist.
     */
    public const MINORS = ['3.2', '3.3', '3.4'];

    public const DEFAULT_MINOR = '3.3';

    /** The official ruby variant every Ruby image here is built from. */
    public const IMAGE_VARIANT = 'slim-bookworm';

    /** The project saying so directly; a Dockerfile states it for another target. */
    private const VERSION_FILE = '.ruby-version';

    /** @var list<string> */
    private const DOCKERFILES = ['Dockerfile', 'Dockerfile.prod'];

    /**
     * Both spellings a Dockerfile uses, `ARG RUBY_VERSION=` first: its FROM line then usually names no version.
     *
     * @var list<string>
     */
    private const DOCKERFILE_PATTERNS = [
        '/^\s*ARG\s+RUBY_VERSION\s*=\s*[\"\']?([0-9]+\.[0-9]+(?:\.[0-9]+)?)/mi',
        '/^FROM\s+\S*ruby:([0-9]+\.[0-9]+(?:\.[0-9]+)?)/mi',
    ];

    /**
     * The minors worth having on the host, oldest first.
     *
     * @return list<string>
     */
    public static function minors(): array
    {
        $configured = RuntimeImageCatalog::versions('ruby');

        return $configured === [] ? self::MINORS : $configured;
    }

    /** What a project that states no version gets. */
    public static function defaultMinor(): string
    {
        return RuntimeImageCatalog::defaultVersion('ruby') ?? self::DEFAULT_MINOR;
    }

    /**
     * The one entry point for "which Ruby does this project get", for any directory: its callers are already
     * inside a Ruby deploy and need an image, not a verdict.
     */
    public static function imageFor(ProjectContext $context): string
    {
        return self::imageTag(self::versionFor($context));
    }

    /** The official image for a Ruby version, in the variant the engine serves with. */
    public static function imageTag(string $version): string
    {
        $spec = RuntimeImageCatalog::spec('ruby', $version);

        return $spec?->from ?? 'ruby:'.$version.'-'.self::IMAGE_VARIANT;
    }

    /** What a project that names no version gets. */
    public const IMAGE = 'ruby:'.self::DEFAULT_MINOR.'-slim-bookworm';

    public function id(): string
    {
        return 'ruby';
    }

    /**
     * Only a project with a Gemfile is Ruby's. A stated version the Gemfile rejects, which bundler refuses,
     * moves to its floor; the log carries both the declaration and the constraints.
     */
    public function resolve(ProjectContext $context): ?Requirement
    {
        if (! $context->hasFile('gemfile')) {
            return null;
        }

        [$version, $source, $raw] = self::statedVersion($context);
        $constraints = Gemfile::of($context)->rubyConstraints();

        if ($constraints !== [] && ! self::satisfies($version, $constraints)) {
            $required = self::lowestAllowed($constraints);
            if ($required !== null) {
                return new Requirement('ruby', $required, implode(', ', $constraints), 'Gemfile ruby directive');
            }
        }

        return new Requirement(
            'ruby',
            $version,
            implode(', ', array_filter(array_merge([$raw], $constraints))),
            $source ?? 'engine default'
        );
    }

    /**
     * What the project states, where, and verbatim, before the Gemfile has a say; the verbatim form lets a log
     * showing our `3.3.0` be checked against the file's `ruby-3.3.0`.
     *
     * @return array{0: string, 1: ?string, 2: string}
     */
    private static function statedVersion(ProjectContext $context): array
    {
        $declared = $context->contents(self::VERSION_FILE);
        if (is_string($declared) && trim($declared) !== '') {
            return [self::normalize($declared), self::VERSION_FILE, trim($declared)];
        }

        $fromDockerfile = self::versionFromDockerfile($context);
        if ($fromDockerfile !== null) {
            return [$fromDockerfile[0], $fromDockerfile[1], $fromDockerfile[0]];
        }

        return [self::defaultMinor(), null, ''];
    }

    /**
     * Does this version satisfy every constraint the Gemfile wrote?
     *
     * @param  list<string>  $constraints
     */
    private static function satisfies(string $version, array $constraints): bool
    {
        foreach ($constraints as $constraint) {
            if (! self::satisfiesOne($version, $constraint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `~> 3` and `~> 3.2` allow 3.x and `~> 3.2.1` 3.2.x, as RubyGems defines them; an exact pin matches
     * by prefix, as bundler means it. Unrecognised syntax never vetoes a version.
     */
    private static function satisfiesOne(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        if (preg_match('/^~>\s*(\d+(?:\.\d+){0,2})$/', $constraint, $m) === 1) {
            $parts = explode('.', $m[1]);
            if (count($parts) > 1) {
                array_pop($parts);
            }
            $prefix = implode('.', $parts);

            return version_compare($version, $m[1], '>=')
                && str_starts_with($version.'.', $prefix.'.');
        }

        if (preg_match('/^(>=|<=|>|<|!=|=)?\s*(\d+(?:\.\d+){0,2})$/', $constraint, $m) === 1) {
            $operator = $m[1] !== '' ? $m[1] : '=';
            if ($operator === '=') {
                return $version === $m[2] || str_starts_with($version.'.', $m[2].'.');
            }

            return version_compare($version, $m[2], $operator);
        }

        return true;
    }

    /**
     * The lowest version the constraints allow, or null; a `>=`/`~>` `X.Y.0` floor collapses to `X.Y`,
     * since Docker Hub prunes a line's oldest patch first.
     *
     * @param  list<string>  $constraints
     */
    private static function lowestAllowed(array $constraints): ?string
    {
        foreach ($constraints as $constraint) {
            $constraint = trim($constraint);
            if (preg_match('/^(>=|~>|=)?\s*(\d+(?:\.\d+){0,2})$/', $constraint, $m) !== 1) {
                continue;
            }

            if (($m[1] === '>=' || $m[1] === '~>') && self::isMinorFloor($m[2])) {
                return substr($m[2], 0, (int) strrpos($m[2], '.'));
            }

            return $m[2];
        }

        return null;
    }

    /** Whether this is `X.Y.0` — the first patch of a minor line. */
    private static function isMinorFloor(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.0$/', $version) === 1;
    }

    public function image(Requirement $requirement): string
    {
        return self::imageTag($requirement->version);
    }

    /**
     * The version this project gets, Gemfile constraint included, sharing `statedVersion()` with `resolve()`
     * so recipe and deploy agree; unlike resolve() it answers for a project that is not Ruby.
     */
    private static function versionFor(ProjectContext $context): string
    {
        [$version] = self::statedVersion($context);
        $constraints = Gemfile::of($context)->rubyConstraints();

        if ($constraints !== [] && ! self::satisfies($version, $constraints)) {
            return self::lowestAllowed($constraints) ?? $version;
        }

        return $version;
    }

    /** "ruby-3.3.0", "3.3.0 # pinned": rbenv and rvm write the prefix, and ruby:ruby-3.3.0 is no tag. */
    private static function normalize(string $raw): string
    {
        $version = trim(explode("\n", $raw)[0]);
        $version = preg_replace('/^ruby-/i', '', $version) ?? $version;

        return preg_replace('/\s+.*/', '', $version) ?? $version;
    }

    /**
     * @return array{0: string, 1: string}|null the version and the file it came from
     */
    private static function versionFromDockerfile(ProjectContext $context): ?array
    {
        foreach (self::DOCKERFILES as $name) {
            $contents = $context->contents($name);
            if ($contents === null) {
                continue;
            }
            foreach (self::DOCKERFILE_PATTERNS as $pattern) {
                if (preg_match($pattern, $contents, $matches) === 1) {
                    return [$matches[1], $name];
                }
            }
        }

        return null;
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('ruby', self::defaultMinor(), '', 'engine default');
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return self::minors();
    }
}
