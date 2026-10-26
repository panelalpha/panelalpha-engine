<?php

namespace App\Lib\Deploy\Platform;

use App\Lib\Deploy\Platform\Probes\ProbeRegistry;

/**
 * Evaluates a manifest's `detect` block against a project directory, and a
 * command's `when` guard with it. Every key of the node must hold.
 *
 * Conditions:
 *   all      list of nodes, all must match
 *   any      list of nodes, at least one must match
 *   none     list of nodes, none may match
 *   not      single node that must not match
 *   file     root-level basename, case-insensitive
 *   path     file at a path relative to the project root
 *   dir      directory at a path relative to the project root
 *   glob     root file whose name starts "<stem>." (next.config.js|mjs|ts)
 *   dep      dependency or devDependency in the root package.json
 *   composer key under require or require-dev in composer.json
 *   script   named entry under package.json scripts
 *   contains {path|glob, pattern, regex?} — read a file, test its contents
 *   probe    named probe, see `PlatformProbe`
 *
 * A scalar condition accepts a list, meaning "any of these":
 * `{"file": ["pom.xml", "build.gradle"]}`.
 */
final class PlatformMatcher
{
    /** @var array<string, PlatformProbe> */
    private array $probes;

    /** @var list<string> extra fields probes contributed during the last match */
    private array $probeData = [];

    /**
     * @param  array<string, PlatformProbe>  $probes  keyed by probe id
     */
    public function __construct(array $probes = [])
    {
        $this->probes = $probes;
    }

    /**
     * Fields the probes in the last `matches()` call contributed.
     *
     * @return array<string, mixed>
     */
    public function lastProbeData(): array
    {
        return $this->probeData;
    }

    /**
     * Commands whose `when` guard holds for this project.
     *
     * @param  list<PlatformCommand>  $commands
     * @return list<PlatformCommand>
     */
    public static function applicable(array $commands, ProjectContext $context): array
    {
        $matcher = new self(ProbeRegistry::all());

        return array_values(array_filter(
            $commands,
            static fn (PlatformCommand $c): bool => $c->when === null || $matcher->matches($c->when, $context)
        ));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function matches(array $node, ProjectContext $context): bool
    {
        $this->probeData = [];

        return $this->evaluate($node, $context);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function evaluate(array $node, ProjectContext $context): bool
    {
        if ($node === []) {
            // Empty matches nothing: matching everything would turn a typo'd
            // key into a platform that claims every project on the host.
            return false;
        }

        foreach ($node as $key => $value) {
            if (! $this->condition((string) $key, $value, $context)) {
                return false;
            }
        }

        return true;
    }

    private function condition(string $key, mixed $value, ProjectContext $context): bool
    {
        return match ($key) {
            'all' => $this->everyNode($value, $context, true),
            'any' => $this->anyNode($value, $context),
            'none' => ! $this->anyNode($value, $context),
            'not' => is_array($value) && ! $this->evaluate($value, $context),
            'file' => $this->anyScalar($value, fn (string $n): bool => $context->hasFile(strtolower($n))),
            'path' => $this->anyScalar($value, fn (string $p): bool => $context->isFile($p)),
            'dir' => $this->anyScalar($value, fn (string $p): bool => is_dir($context->path($p))),
            'glob' => $this->anyScalar($value, fn (string $s): bool => $context->hasConfigStem($s)),
            'dep' => $this->anyScalar($value, fn (string $d): bool => $context->hasDep($d)),
            'script' => $this->anyScalar($value, fn (string $s): bool => $context->script($s) !== ''),
            'composer' => $this->anyScalar($value, fn (string $p): bool => self::composerRequires($context, $p)),
            'contains' => is_array($value) && $this->contains($value, $context),
            'probe' => $this->anyScalar($value, fn (string $p): bool => $this->probe($p, $context)),
            // Ignoring an unknown key would widen the predicate.
            default => throw new ManifestException("Unknown detect condition '{$key}'"),
        };
    }

    private function everyNode(mixed $nodes, ProjectContext $context, bool $emptyResult): bool
    {
        if (! is_array($nodes)) {
            throw new ManifestException("'all' must be a list of conditions");
        }
        if ($nodes === []) {
            return $emptyResult;
        }
        foreach ($nodes as $child) {
            if (! is_array($child) || ! $this->evaluate($child, $context)) {
                return false;
            }
        }

        return true;
    }

    private function anyNode(mixed $nodes, ProjectContext $context): bool
    {
        if (! is_array($nodes)) {
            throw new ManifestException("'any'/'none' must be a list of conditions");
        }
        foreach ($nodes as $child) {
            if (is_array($child) && $this->evaluate($child, $context)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A scalar condition accepts one value or a list of alternatives.
     */
    private function anyScalar(mixed $value, callable $test): bool
    {
        foreach (is_array($value) ? $value : [$value] as $item) {
            if (is_string($item) && $item !== '' && $test($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    private function contains(array $spec, ProjectContext $context): bool
    {
        $pattern = $spec['pattern'] ?? null;
        if (! is_string($pattern) || $pattern === '') {
            throw new ManifestException("'contains' needs a non-empty 'pattern'");
        }

        $haystack = null;
        if (isset($spec['path']) && is_string($spec['path'])) {
            $haystack = $context->contents($spec['path']);
        } elseif (isset($spec['glob']) && is_string($spec['glob'])) {
            $haystack = $context->configContents($spec['glob']);
        } else {
            throw new ManifestException("'contains' needs a 'path' or a 'glob'");
        }

        if (! is_string($haystack) || $haystack === '') {
            return false;
        }

        if (($spec['regex'] ?? false) === true) {
            // `@` so a bad pattern reads as a failed match, not a PHP warning.
            return @preg_match('/'.str_replace('/', '\\/', $pattern).'/', $haystack) === 1;
        }

        return str_contains($haystack, $pattern);
    }

    private function probe(string $id, ProjectContext $context): bool
    {
        $probe = $this->probes[$id] ?? null;
        if ($probe === null) {
            throw new ManifestException("Unknown probe '{$id}'");
        }

        $result = $probe->evaluate($context);
        if (is_array($result)) {
            $this->probeData = array_merge($this->probeData, $result);

            return true;
        }

        return $result;
    }

    private static function composerRequires(ProjectContext $context, string $package): bool
    {
        $composer = $context->composer();
        if ($composer === null) {
            return false;
        }
        foreach (['require', 'require-dev'] as $section) {
            $entries = $composer[$section] ?? null;
            if (is_array($entries) && array_key_exists($package, $entries)) {
                return true;
            }
        }

        return false;
    }
}
