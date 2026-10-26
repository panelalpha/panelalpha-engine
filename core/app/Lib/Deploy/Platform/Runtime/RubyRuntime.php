<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\ProjectContext;
use App\Lib\Deploy\Platform\Runtime\Ruby\Gemfile;

/**
 * Ruby, versioned by `.ruby-version`, then by `ARG RUBY_VERSION=` or a
 * `FROM ruby:<version>` in the repo's own Dockerfile (Rails 7.1+ generates
 * one). Then, when the stated version violates the Gemfile's `ruby`
 * constraint, by the constraint's floor.
 *
 * Whatever the project declares is honoured, including a version outside
 * `MINORS` — that list is what the host warms, not a whitelist: a Gemfile.lock
 * resolved under one minor will not install under another.
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
     * Both spellings a Dockerfile uses. `ARG RUBY_VERSION=` first: such a
     * Dockerfile usually then writes `FROM ruby:$RUBY_VERSION`, where the FROM
     * line names no version.
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
     * The one entry point for "which Ruby does this project get". Unlike
     * `resolve()` it answers for any directory: its callers are already inside
     * a Ruby deploy and need an image, not a verdict on the project.
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

    public function resolve(ProjectContext $context): ?Requirement
    {
        // The Gemfile gate belongs here, not in imageFor(): this method answers
        // "is this project mine", and a .ruby-version with no Gemfile is not a
        // Ruby application the engine can deploy.
        if (! $context->hasFile('gemfile')) {
            return null;
        }

        [$version, $source, $raw] = self::statedVersion($context);
        $constraints = Gemfile::of($context)->rubyConstraints();

        // The Gemfile constrains, it does not dictate. bundler refuses to
        // install under a Ruby the Gemfile rejects (`Your Ruby version is
        // 3.3.0, but your Gemfile specified 3.2.2`), so a violation is a failed
        // deploy — but a range already satisfied must not move to its floor.
        if ($constraints !== [] && ! self::satisfies($version, $constraints)) {
            $required = self::lowestAllowed($constraints);
            if ($required !== null) {
                return new Requirement('ruby', $required, implode(', ', $constraints), 'Gemfile ruby directive');
            }
        }

        // Both the verbatim declaration and the Gemfile constraints go in the
        // deploy log: reporting either alone makes the other look absent.
        return new Requirement(
            'ruby',
            $version,
            implode(', ', array_filter(array_merge([$raw], $constraints))),
            $source ?? 'engine default'
        );
    }

    /**
     * What the project states, where it said so, and the declaration verbatim
     * — before the Gemfile gets a say on whether it is allowed. The verbatim
     * form is what the requirement reports, so a log showing only our `3.3.0`
     * can still be checked against the file's `ruby-3.3.0`.
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

    private static function satisfiesOne(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);

        // `~> 3.2` allows 3.x; `~> 3.2.1` allows 3.2.x — the last named
        // component may move.
        if (preg_match('/^~>\s*(\d+(?:\.\d+){0,2})$/', $constraint, $m) === 1) {
            $parts = explode('.', $m[1]);
            array_pop($parts);
            $prefix = implode('.', $parts);

            return version_compare($version, $m[1], '>=')
                && ($prefix === '' || str_starts_with($version.'.', $prefix.'.'));
        }

        if (preg_match('/^(>=|<=|>|<|!=|=)?\s*(\d+(?:\.\d+){0,2})$/', $constraint, $m) === 1) {
            $operator = $m[1] !== '' ? $m[1] : '=';
            if ($operator === '=') {
                // An exact pin matches by prefix: `3.2` is satisfied by 3.2.2,
                // which is what the pin means to bundler.
                return $version === $m[2] || str_starts_with($version.'.', $m[2].'.');
            }

            return version_compare($version, $m[2], $operator);
        }

        // Unrecognised syntax must not veto a version.
        return true;
    }

    /**
     * The lowest version the constraints allow, for when the stated one does
     * not satisfy them. Null when nothing here names a floor.
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

            // Docker Hub prunes the oldest patch of a line first
            // (ruby:3.4.0-slim-bookworm is gone), so a `>= X.Y.0` floor
            // collapses to the minor line, which is the same floor and is what
            // the host warms. Only a `.0` floor: `~> 3.3.9` would go *below*
            // what the project asked for.
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
     * The version this project gets, Gemfile constraint included. Shares
     * `statedVersion()` with `resolve()`, so the recipe and the deploy cannot
     * resolve different versions. Differs from resolve() only for a project
     * that is not Ruby at all — this answers anyway.
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

    private static function normalize(string $raw): string
    {
        // "ruby-3.3.0", "3.3.0 # pinned": rbenv and rvm write the prefixed
        // form, and ruby:ruby-3.3.0 is not a tag any registry has.
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
