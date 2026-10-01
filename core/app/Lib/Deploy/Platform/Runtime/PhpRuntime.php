<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\CacheManager\PhpBaseImage;
use App\Lib\Deploy\Detect\PhpSources;
use App\Lib\Deploy\Platform\ProjectContext;

/**
 * PHP, versioned by composer only: `require.php` in composer.json *and* the
 * same key in every package pinned by composer.lock. A project declaring
 * `^8.1` still gets 8.3 when a locked dependency demands it, which is what
 * stops the build dying on `requires php ^8.3, your php version (8.1)`.
 *
 * Sources in precedence: `composer.lock` `platform-overrides`, then
 * `platform`, then the constraints, then `require.php`. Of the minors
 * satisfying every constraint the **lowest** wins, except that a project with
 * no lock gets the default minor when it satisfies them; an unrecognised
 * constraint disqualifies a minor instead of passing it.
 */
final class PhpRuntime implements Runtime
{
    /**
     * The compiled-in fallback for the minors the engine publishes images for,
     * oldest first. `minors()` prefers the catalogue.
     */
    public const MINORS = ['8.1', '8.2', '8.3', '8.4', '8.5'];

    /**
     * The official php variant every PHP image here is built from.
     *
     * `apache`, not `cli`: the deployed app is served by Apache and needs
     * .htaccess. It also decides which base images get prewarmed.
     *
     * The catalogue's `image.from` supersedes it; this remains the answer for a
     * host with no catalogue entry.
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

    public function resolve(ProjectContext $context): ?Requirement
    {
        $composerJson = $context->contents('composer.json');
        if ($composerJson === null) {
            // A hand-written site declares no version anywhere but still needs
            // an interpreter: null here left php-plain unresolvable. PHP reads
            // composer only, so `PhpSources` decides "still PHP".
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

    public static function requirementFor(?string $composerJson, ?string $composerLock = null): Requirement
    {
        // A lock is the answer, not a set of constraints: `composer install`
        // reads the platform out of `platform-overrides` and never consults the
        // running interpreter, `require.php` or `config.platform`.
        //
        // Unless the lock contradicts itself — `php: 8.2` overridden while
        // packages requiring 8.4 are locked (the shape a lock has after
        // `--ignore-platform-reqs`) — since the install is relaxed with
        // `--ignore-platform-req=php` either way, and a green deploy would then
        // run PHP 8.2 on packages that reject it. The version falls through to
        // the constraint path instead: relax what is installed, not what it is
        // installed on.
        if (($locked = self::lockedPlatform($composerLock)) !== null
            && !self::lockedPhpContradicted($composerLock)
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
            // Without a lock the build runs `composer update`, which takes the
            // newest releases, and those routinely need more than the project's
            // floor (Aimeos: `^8.1` beside laravel/framework ^13, which needs
            // 8.3). The default minor when the floor allows it, as for Node.
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
        }

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

        return new Requirement('php', "{$major}.{$minor}", implode(', ', $constraints), 'composer.json require.php');
    }

    /**
     * @param list<string> $constraints
     */
    private static function allowsMinor(array $constraints, string $minor): bool
    {
        foreach ($constraints as $constraint) {
            // An unrecognised constraint disqualifies the minor. Reading it as
            // compatible would let a garbage `require.php` select the *oldest*
            // PHP the engine ships.
            if (self::constraintAllowsVersion($constraint, $minor . '.999999') !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * The PHP minor a lock was resolved for, or null when it names none.
     *
     * `platform-overrides` outranks `platform`: overrides is what a build sets
     * locally and what `install` obeys, while `platform` is a resolved
     * *packages* map and usually carries a constraint instead.
     *
     * Admitted only when shaped like a minor *and* one the engine publishes an
     * image for — the raw string ends up in an image tag, and `7.2` would name
     * a base image that does not exist.
     */
    public static function lockedPlatform(?string $composerLock): ?string
    {
        $minor = self::lockedPhpMinors($composerLock);

        return $minor !== null && in_array($minor, self::minors(), true) ? $minor : null;
    }

    /**
     * The minor the lock states, whether or not the engine publishes it, which
     * is what `composer install` will apply: htmly pins 7.2 and the install
     * verifies against it even though the account runs 8.1.
     */
    private static function lockedPhpMinors(?string $composerLock): ?string
    {
        $lock = self::decodeObject($composerLock);
        foreach (['platform-overrides', 'platform'] as $key) {
            $value = $lock[$key]['php'] ?? null;
            // `8.2`, `8.2.0` and `8.2.7` are one minor; `>=8.2` is left to the
            // constraint path.
            if (is_string($value)
                && preg_match('/^v?(\d+\.\d+)(?:\.\d+)?$/', trim($value), $m) === 1
            ) {
                return $m[1];
            }
        }

        return null;
    }

    /**
     * Whether a lock states a platform that a package it pinned rejects — the
     * shape a lock has after `composer install --ignore-platform-reqs`, which
     * egroupware's CI does. `install` must not repair that, so the caller stops
     * enforcing the php requirement for that build instead.
     *
     * The stated platform is taken as-is, not through `lockedPlatform()`: a pin
     * outside our catalogue is where the contradiction is most likely.
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
                && self::constraintAllowsVersion(trim($requires), $locked . '.999999') === false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The locked *runtime* packages. `packages-dev` is left out: `composer
     * install --no-dev` loads only the non-dev repository, so a dev package's
     * `require.php` is never checked, and counting it would let a package that
     * is not installed decide which minor the image is built on.
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
     * The image a PHP project runs on: the shared base the engine builds
     * (`panelalpha/php:…-pa<fingerprint>`), not the official tag it is built
     * from, because that base is what gets seeded into accounts.
     *
     * Null from `PhpBaseImage::tag()` means the catalogue describes no PHP
     * build on this host; the stock image is then the honest answer.
     */
    public function image(Requirement $requirement): string
    {
        $stock = self::imageTag($requirement->version);

        return PhpBaseImage::tag($stock) ?? $stock;
    }

    /**
     * The official image for a PHP minor, in the variant the engine serves
     * with: the *upstream* tag, not the shared base built from it.
     * `PhpBaseImage::tag()` turns this into `panelalpha/php:…-pa<fingerprint>`
     * and needs this spelling to recognise a plain official tag.
     */
    public static function imageTag(string $minor): string
    {
        $spec = RuntimeImageCatalog::spec('php', $minor);

        return $spec?->from ?? "php:{$minor}-" . self::IMAGE_VARIANT;
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
     * Conservative Composer-constraint evaluator for PHP platform
     * requirements: the operators composer.json/lock emit in practice, without
     * Composer's semver package. Null means the syntax was unrecognised.
     */
    private static function constraintAllowsVersion(string $constraint, string $version): ?bool
    {
        $constraint = trim((string) preg_replace('/\s+@(?:dev|alpha|beta|rc|stable)\b/i', '', $constraint));
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
            if (!$tokenResult) {
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
            $operator = $match[1] !== '' ? $match[1] : '=';
            $required = self::normalizePhpVersion($match[2]);
            if ($operator === '=' || $operator === '==') {
                $candidateParts = explode('.', $version);
                $requiredParts = explode('.', $match[2]);

                return (int) $candidateParts[0] === (int) $requiredParts[0]
                    && (!isset($requiredParts[1]) || (int) $candidateParts[1] === (int) $requiredParts[1]);
            }

            return version_compare($version, $required, $operator);
        }
        if (preg_match('/^[~^]v?(\d+(?:\.\d+){0,2})$/i', $token, $match) === 1) {
            $lower = self::normalizePhpVersion($match[1]);
            $parts = array_map('intval', explode('.', $match[1]));
            if ($token[0] === '^') {
                $upper = ($parts[0] + 1) . '.0.0';
            } elseif (count($parts) >= 3) {
                $upper = $parts[0] . '.' . ($parts[1] + 1) . '.0';
            } else {
                $upper = ($parts[0] + 1) . '.0.0';
            }

            return version_compare($version, $lower, '>=') && version_compare($version, $upper, '<');
        }
        if (preg_match('/^v?(\d+)(?:\.(\d+))?\.(?:x|\*)$/i', $token, $match) === 1) {
            $major = (int) $match[1];
            $minor = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null;
            [$candidateMajor, $candidateMinor] = array_map('intval', explode('.', $version, 3));

            return $candidateMajor === $major && ($minor === null || $candidateMinor === $minor);
        }

        return null;
    }

    private static function upperBoundForPartialVersion(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));
        if (count($parts) === 1) {
            return ($parts[0] + 1) . '.0.0';
        }

        return $parts[0] . '.' . ($parts[1] + 1) . '.0';
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
                if (!is_array($package) || !isset($package['require']) || !is_array($package['require'])) {
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


    private static function minimumPhpVersion(?string $composerJson, ?string $composerLock): string
    {
        // The engine's default, so a host configuring a different one gets it here too.
        $minimum = self::normalizePhpVersion(self::defaultMinor());
        $found = false;
        foreach (self::requireMaps($composerJson, $composerLock) as $require) {
            if (!isset($require['php']) || !is_string($require['php'])) {
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
        $constraint = trim($constraint);
        if ($constraint === '') {
            return null;
        }

        $branchMins = [];
        foreach (preg_split('/\s*\|\|\s*/', $constraint) ?: [] as $part) {
            if (!is_string($part)) {
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
     * `8.3`, `8.3.0` and `8` are the same version written three ways, and a
     * composer constraint may use any of them. Padded to major.minor.patch so
     * `version_compare()` works.
     *
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

    private static function bumpPhpVersion(string $version): string
    {
        $parts = explode('.', $version);
        $major = (int) ($parts[0] ?? 8);
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) ($parts[2] ?? 0);
        if ($patch > 0) {
            $patch++;
        } elseif ($minor > 0) {
            $minor++;
            $patch = 0;
        } else {
            $major++;
            $minor = 0;
            $patch = 0;
        }

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
