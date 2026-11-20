<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Detect\PhpSources;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * PHP, versioned by composer only: the lock's platform, else the lowest minor every `require.php` in composer.json
 * and composer.lock allows (the default without a lock), an unrecognised constraint disqualifying a minor.
 */
final class PhpRuntime implements Runtime
{
    /**
     * The compiled-in fallback for the minors the engine publishes images for,
     * oldest first. `minors()` prefers the catalogue.
     */
    public const MINORS = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    /**
     * The official variant PHP images are built from: `apache`, as the app needs .htaccess. The catalogue's
     * `image.from` supersedes it; this answers for a host with no catalogue entry.
     */
    public const IMAGE_VARIANT = 'apache-bookworm';

    /** Compiled-in fallback for the catalogue's `default`. */
    public const DEFAULT_MINOR = '8.3';

    /**
     * The minors a project may resolve to, oldest first — load-bearing, since
     * `requirementFor()` takes the *first* minor satisfying every constraint.
     *
     * @return list<string>
     */
    public static function minors(): array
    {
        $configured = RuntimeImageCatalog::versions('php');

        return $configured === [] ? self::MINORS : $configured;
    }

    /** What a project that says nothing about PHP gets. */
    public static function defaultMinor(): string
    {
        return RuntimeImageCatalog::defaultVersion('php') ?? self::DEFAULT_MINOR;
    }

    public function id(): string
    {
        return 'php';
    }

    /** A hand-written site without composer.json still needs an interpreter: `PhpSources` decides it is PHP. */
    public function resolve(ProjectContext $context): ?Requirement
    {
        $composerJson = $context->contents('composer.json');
        if ($composerJson === null) {
            return PhpSources::present($context->projectDir) ? $this->defaultRequirement() : null;
        }

        return self::requirementFor($composerJson, $context->contents('composer.lock'));
    }

    /**
     * The one entry point for "which PHP does this project get", from its
     * composer files alone.
     */
    public static function imageFor(?string $composerJson, ?string $composerLock = null): string
    {
        return self::imageTag(self::requirementFor($composerJson, $composerLock)->version);
    }

    /**
     * A consistent lock's platform is the answer, since `composer install` obeys it; a lock contradicting its
     * own packages falls through to the constraints, as its install is relaxed with `--ignore-platform-req=php`.
     */
    public static function requirementFor(?string $composerJson, ?string $composerLock = null): Requirement
    {
        if (($locked = self::lockedPlatform($composerLock)) !== null
            && ! self::lockedPhpContradicted($composerLock)
        ) {
            return new Requirement(
                'php',
                $locked,
                implode(', ', self::phpConstraints($composerJson, $composerLock)),
                'composer.lock platform'
            );
        }

        $constraints = self::phpConstraints($composerJson, $composerLock);
        if ($constraints !== []) {
            $satisfying = self::satisfyingRequirement($constraints, $composerJson, $composerLock);
            if ($satisfying !== null) {
                return $satisfying;
            }
        }

        return self::minimumRequirement($constraints, $composerJson, $composerLock);
    }

    /**
     * The lowest minor all constraints allow; without a lock the default when allowed, since `composer update`
     * takes the newest releases (Aimeos's `^8.1` beside laravel/framework ^13 needs 8.3).
     *
     * @param  list<string>  $constraints
     */
    private static function satisfyingRequirement(array $constraints, ?string $composerJson, ?string $composerLock): ?Requirement
    {
        $default = self::defaultMinor();
        if (self::decodeObject($composerLock) === []
            && in_array($default, self::minors(), true)
            && self::allowsMinor($constraints, $default)
        ) {
            return new Requirement('php', $default, implode(', ', $constraints), self::sourceFor($composerJson, $composerLock));
        }
        foreach (self::minors() as $minor) {
            if (self::allowsMinor($constraints, $minor)) {
                return new Requirement(
                    'php',
                    $minor,
                    implode(', ', $constraints),
                    self::sourceFor($composerJson, $composerLock)
                );
            }
        }

        return null;
    }

    /**
     * The first minor at or above the highest floor, the default's included, else that floor itself.
     *
     * @param  list<string>  $constraints
     */
    private static function minimumRequirement(array $constraints, ?string $composerJson, ?string $composerLock): Requirement
    {
        $minimum = self::minimumPhpVersion($composerJson, $composerLock);
        [$major, $minor] = array_map('intval', explode('.', self::normalizePhpVersion($minimum), 3));
        foreach (self::minors() as $known) {
            [$knownMajor, $knownMinor] = array_map('intval', explode('.', $known, 2));
            if ($knownMajor > $major || ($knownMajor === $major && $knownMinor >= $minor)) {
                return new Requirement(
                    'php',
                    $known,
                    $constraints === [] ? '' : implode(', ', $constraints),
                    $constraints === [] ? 'engine default' : self::sourceFor($composerJson, $composerLock)
                );
            }
        }

        return new Requirement('php', "{$major}.{$minor}", implode(', ', $constraints), self::floorSource($composerJson, $composerLock));
    }

    /** Where a floor above the catalogue came from: a root `require.php` stating no version (`@dev`) leaves it to the lock. */
    private static function floorSource(?string $composerJson, ?string $composerLock): string
    {
        $rootPhp = self::decodeObject($composerJson)['require']['php'] ?? null;
        $rootStatesFloor = is_string($rootPhp) && self::constraintMinimumPhp($rootPhp) !== null;

        return self::sourceFor($rootStatesFloor ? $composerJson : null, $composerLock);
    }

    /**
     * An unrecognised constraint disqualifies the minor, or a garbage `require.php` would select the oldest PHP.
     *
     * @param  list<string>  $constraints
     */
    private static function allowsMinor(array $constraints, string $minor): bool
    {
        foreach ($constraints as $constraint) {
            if (self::constraintAllowsVersion($constraint, $minor.'.999999') !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * The minor a lock was resolved for, `platform-overrides` first, or null; only one the engine publishes an
     * image for, since the string ends up in an image tag.
     */
    public static function lockedPlatform(?string $composerLock): ?string
    {
        $minor = self::lockedPhpMinors($composerLock);

        return $minor !== null && in_array($minor, self::minors(), true) ? $minor : null;
    }

    /**
     * The minor the lock states, published or not, which `composer install` applies (htmly pins 7.2).
     * `8.2`, `8.2.0` and `8.2.7` are one minor; `>=8.2` is left to the constraint path.
     */
    private static function lockedPhpMinors(?string $composerLock): ?string
    {
        $lock = self::decodeObject($composerLock);
        foreach (['platform-overrides', 'platform'] as $key) {
            $value = $lock[$key]['php'] ?? null;
            if (is_string($value)
                && preg_match('/^v?(\d+\.\d+)(?:\.\d+)?$/', trim($value), $m) === 1
            ) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Whether a lock states a platform a package it pinned rejects (after `--ignore-platform-reqs`, egroupware),
     * taken as-is: a pin outside our catalogue is where that is most likely.
     */
    public static function lockedPhpContradicted(?string $composerLock): bool
    {
        $locked = self::lockedPhpMinors($composerLock);
        if ($locked === null) {
            return false;
        }

        foreach (self::decodedPackages($composerLock) as $package) {
            $requires = $package['require']['php'] ?? null;
            if (is_string($requires)
                && trim($requires) !== ''
                && self::constraintAllowsVersion(trim($requires), $locked.'.999999') === false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The locked package rejecting $minor, as `name version (php X)`, when the lock states no minor and the root
     * allows $minor; null when the root says nothing or rules $minor out itself.
     */
    public static function lockedPackageRejecting(?string $composerJson, ?string $composerLock, string $minor): ?string
    {
        if (self::lockedPhpMinors($composerLock) !== null) {
            return null;
        }
        $root = self::decodeObject($composerJson)['require']['php'] ?? null;
        if (! is_string($root) || trim($root) === ''
            || self::constraintAllowsVersion(trim($root), $minor.'.999999') !== true
        ) {
            return null;
        }

        foreach (self::decodedPackages($composerLock) as $package) {
            $requires = $package['require']['php'] ?? null;
            if (is_string($requires) && trim($requires) !== ''
                && self::constraintAllowsVersion(trim($requires), $minor.'.999999') === false
            ) {
                return trim(($package['name'] ?? '?').' '.($package['version'] ?? '')).' (php '.trim($requires).')';
            }
        }

        return null;
    }

    /**
     * The locked runtime packages: `install --no-dev` never checks a dev package's `require.php`, so it must not
     * decide the minor.
     *
     * @return list<array<string, mixed>>
     */
    private static function decodedPackages(?string $composerLock): array
    {
        $lock = self::decodeObject($composerLock);
        $packages = [];
        foreach ($lock['packages'] ?? [] as $package) {
            if (is_array($package)) {
                $packages[] = $package;
            }
        }

        return $packages;
    }

    /**
     * The shared base the engine builds and seeds into accounts, not the official tag it is built from;
     * the stock image when the catalogue describes no PHP build on this host.
     */
    public function image(Requirement $requirement): string
    {
        $stock = self::imageTag($requirement->version);

        return PhpBaseImage::tag($stock) ?? $stock;
    }

    /**
     * The upstream image for a PHP minor in the served variant, the spelling `PhpBaseImage::tag()` recognises
     * and turns into `panelalpha/php:…-pa<fingerprint>`.
     */
    public static function imageTag(string $minor): string
    {
        $spec = RuntimeImageCatalog::spec('php', $minor);

        return $spec?->from ?? "php:{$minor}-".self::IMAGE_VARIANT;
    }

    /**
     * Names the file that decided the version: `require.php` alone, narrowed
     * by composer.lock, or composer.lock via a locked dependency.
     */
    private static function sourceFor(?string $composerJson, ?string $composerLock): string
    {
        $root = self::decodeObject($composerJson);
        if (isset($root['require']['php']) && is_string($root['require']['php'])) {
            $lockNarrows = false;
            $lock = self::decodeObject($composerLock);
            foreach ($lock['packages'] ?? [] as $package) {
                if (is_array($package) && isset($package['require']['php'])) {
                    $lockNarrows = true;
                    break;
                }
            }

            return $lockNarrows
                ? 'composer.json require.php, narrowed by composer.lock'
                : 'composer.json require.php';
        }

        return 'composer.lock, via a locked dependency';
    }

    /**
     * Composer constraints encountered in the root manifest and locked runtime
     * packages. The selected PHP minor must satisfy all recognised constraints.
     *
     * @return list<string>
     */
    private static function phpConstraints(?string $composerJson, ?string $composerLock): array
    {
        $constraints = [];
        foreach (self::requireMaps($composerJson, $composerLock) as $require) {
            $value = $require['php'] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $constraints[] = trim($value);
            }
        }

        return $constraints;
    }

    /**
     * A conservative evaluator for the operators composer.json/lock emit in practice, without Composer's semver.
     * Null means the syntax was unrecognised.
     */
    private static function constraintAllowsVersion(string $constraint, string $version): ?bool
    {
        $constraint = self::withoutStabilityFlags($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        $recognized = false;
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $branch) {
            $branch = trim($branch);
            if ($branch === '') {
                continue;
            }
            $branchResult = self::constraintBranchAllowsVersion($branch, $version);
            if ($branchResult === null) {
                continue;
            }
            $recognized = true;
            if ($branchResult) {
                return true;
            }
        }

        return $recognized ? false : null;
    }

    /** `^8.2@dev` and `^8.2 @dev` both mean `^8.2`, as in Composer's VersionParser. */
    private static function withoutStabilityFlags(string $constraint): string
    {
        return trim((string) preg_replace('/\s*@(?:dev|alpha|beta|rc|stable)\b/i', '', $constraint));
    }

    private static function constraintBranchAllowsVersion(string $branch, string $version): ?bool
    {
        if (preg_match('/^(\d+(?:\.\d+){0,2})\s+-\s+(\d+(?:\.\d+){0,2})$/', $branch, $range) === 1) {
            return version_compare($version, self::normalizePhpVersion($range[1]), '>=')
                && version_compare($version, self::upperBoundForPartialVersion($range[2]), '<');
        }

        $tokens = preg_split('/(?:\s*,\s*|\s+)/', trim($branch)) ?: [];
        $matched = false;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            $tokenResult = self::constraintTokenAllowsVersion($token, $version);
            if ($tokenResult === null) {
                return null;
            }
            $matched = true;
            if (! $tokenResult) {
                return false;
            }
        }

        return $matched ? true : null;
    }

    private static function constraintTokenAllowsVersion(string $token, string $version): ?bool
    {
        if ($token === '*' || strtolower($token) === 'x') {
            return true;
        }
        if (preg_match('/^(>=|<=|>|<|!=|==|=)?\s*v?(\d+(?:\.\d+){0,2})$/i', $token, $match) === 1) {
            return self::comparisonAllowsVersion($match, $version);
        }
        if (preg_match('/^[~^]v?(\d+(?:\.\d+){0,2})$/i', $token, $match) === 1) {
            return self::rangeOperatorAllowsVersion($token, $match, $version);
        }
        if (preg_match('/^v?(\d+)(?:\.(\d+))?\.(?:x|\*)$/i', $token, $match) === 1) {
            return self::wildcardAllowsVersion($match, $version);
        }

        return null;
    }

    /**
     * `>=8.2`, `!=8.1` or a bare `8.2`, which matches the major and, when named, the minor.
     *
     * @param  array<int, string>  $match
     */
    private static function comparisonAllowsVersion(array $match, string $version): bool
    {
        $operator = $match[1] !== '' ? $match[1] : '=';
        $required = self::normalizePhpVersion($match[2]);
        if ($operator === '=' || $operator === '==') {
            $candidateParts = explode('.', $version);
            $requiredParts = explode('.', $match[2]);

            return (int) $candidateParts[0] === (int) $requiredParts[0]
                && (! isset($requiredParts[1]) || (int) $candidateParts[1] === (int) $requiredParts[1]);
        }

        return version_compare($version, $required, $operator);
    }

    /**
     * `^8.2` up to the next major, `~8.2.1` up to the next minor, `~8.2` up to the next major.
     *
     * @param  array<int, string>  $match
     */
    private static function rangeOperatorAllowsVersion(string $token, array $match, string $version): bool
    {
        $lower = self::normalizePhpVersion($match[1]);
        $parts = array_map('intval', explode('.', $match[1]));
        if ($token[0] === '^') {
            $upper = ($parts[0] + 1).'.0.0';
        } elseif (count($parts) >= 3) {
            $upper = $parts[0].'.'.($parts[1] + 1).'.0';
        } else {
            $upper = ($parts[0] + 1).'.0.0';
        }

        return version_compare($version, $lower, '>=') && version_compare($version, $upper, '<');
    }

    /**
     * `8.x` or `8.2.*`.
     *
     * @param  array<int, string>  $match
     */
    private static function wildcardAllowsVersion(array $match, string $version): bool
    {
        $major = (int) $match[1];
        $minor = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null;
        [$candidateMajor, $candidateMinor] = array_map('intval', explode('.', $version, 3));

        return $candidateMajor === $major && ($minor === null || $candidateMinor === $minor);
    }

    private static function upperBoundForPartialVersion(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));
        if (count($parts) === 1) {
            return ($parts[0] + 1).'.0.0';
        }

        return $parts[0].'.'.($parts[1] + 1).'.0';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function requireMaps(?string $composerJson, ?string $composerLock): array
    {
        $maps = [];
        $json = self::decodeObject($composerJson);
        if (isset($json['require']) && is_array($json['require'])) {
            $maps[] = $json['require'];
        }
        $lock = self::decodeObject($composerLock);
        if (isset($lock['packages']) && is_array($lock['packages'])) {
            foreach ($lock['packages'] as $package) {
                if (! is_array($package) || ! isset($package['require']) || ! is_array($package['require'])) {
                    continue;
                }
                $maps[] = $package['require'];
            }
        }

        return $maps;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeObject(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Starts from the engine's default, so a host configuring a different one gets it here too. */
    private static function minimumPhpVersion(?string $composerJson, ?string $composerLock): string
    {
        $minimum = self::normalizePhpVersion(self::defaultMinor());
        $found = false;
        foreach (self::requireMaps($composerJson, $composerLock) as $require) {
            if (! isset($require['php']) || ! is_string($require['php'])) {
                continue;
            }
            $constraintMin = self::constraintMinimumPhp($require['php']);
            if ($constraintMin === null) {
                continue;
            }
            $found = true;
            if (version_compare($constraintMin, $minimum, '>')) {
                $minimum = $constraintMin;
            }
        }

        return $found ? $minimum : self::normalizePhpVersion(self::defaultMinor());
    }

    private static function constraintMinimumPhp(string $constraint): ?string
    {
        $constraint = self::withoutStabilityFlags($constraint);
        if ($constraint === '') {
            return null;
        }

        $branchMins = [];
        foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [] as $part) {
            if (! is_string($part)) {
                continue;
            }
            $partMin = self::singleConstraintMinimumPhp(trim($part));
            if ($partMin !== null) {
                $branchMins[] = $partMin;
            }
        }
        if ($branchMins === []) {
            return null;
        }
        usort($branchMins, 'version_compare');

        return $branchMins[0];
    }

    private static function singleConstraintMinimumPhp(string $part): ?string
    {
        if (preg_match('/>=\s*(\d+\.\d+(?:\.\d+)?)/', $part, $matches)) {
            return self::normalizePhpVersion($matches[1]);
        }
        if (preg_match('/>\s*(\d+\.\d+(?:\.\d+)?)/', $part, $matches)) {
            return self::bumpPhpVersion($matches[1]);
        }
        if (preg_match('/\^(\d+\.\d+(?:\.\d+)?)/', $part, $matches)) {
            return self::normalizePhpVersion($matches[1]);
        }
        if (preg_match('/~(\d+\.\d+(?:\.\d+)?)/', $part, $matches)) {
            return self::normalizePhpVersion($matches[1]);
        }
        if (preg_match('/^(\d+\.\d+)(?:\.\d+)?(?:\.\*)?$/', $part, $matches)) {
            return self::normalizePhpVersion($matches[1]);
        }

        return null;
    }

    /**
     * `8.3`, `8.3.0` and `8` padded to major.minor.patch for `version_compare()`.
     * Public because `Images` selects a base image tag from the same strings.
     */
    public static function normalizePhpVersion(string $version): string
    {
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 8);
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) ($parts[2] ?? 0);

        return "{$major}.{$minor}.{$patch}";
    }

    /** The lowest release above `>x.y[.z]`, the next patch: `>9.1` admits 9.1.1. */
    private static function bumpPhpVersion(string $version): string
    {
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 8);
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) ($parts[2] ?? 0) + 1;

        return "{$major}.{$minor}.{$patch}";
    }

    public function defaultRequirement(): Requirement
    {
        return new Requirement('php', self::defaultMinor(), '', 'engine default');
    }

    /**
     * @return list<string>
     */
    public function supportedVersions(): array
    {
        return self::minors();
    }
}
