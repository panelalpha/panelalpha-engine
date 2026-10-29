<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * One toolchain a project needs, at the version it needs, and why.
 *
 * `source` is not decoration. Today a deploy log says `php:8.3-apache-bookworm`
 * and nothing about where 8.3 came from — but the constraint that decided it
 * is as often a locked dependency's `require.php` as the root manifest, and
 * that is precisely the case people cannot explain to themselves. A
 * requirement that cannot say why it exists is a support ticket.
 *
 * No Laravel dependencies — unit-testable.
 */
final class Requirement
{
    /** Must be present in the image the application runs in. */
    public const ROLE_RUNTIME = 'runtime';

    /** Needed to build the application, never at runtime. Gets its own stage. */
    public const ROLE_BUILD = 'build';

    public function __construct(
        public readonly string $id,
        public readonly string $version,
        public readonly string $constraint,
        public readonly string $source,
        public readonly string $role = self::ROLE_RUNTIME
    ) {}

    /**
     * `php-8.3`. The canonical name for this requirement, used to build
     * deterministic image tags for a set of them.
     */
    public function token(): string
    {
        return $this->id.'-'.$this->version;
    }

    public function withRole(string $role): self
    {
        return new self($this->id, $this->version, $this->constraint, $this->source, $role);
    }

    public function isRuntime(): bool
    {
        return $this->role === self::ROLE_RUNTIME;
    }

    /** What a deploy log should print. */
    public function explain(): string
    {
        return "{$this->id} {$this->version} (from {$this->source})";
    }

    /**
     * Canonical token for a set: sorted, de-duplicated, joined.
     *
     * Sorted because the same set must produce the same string whatever order
     * the manifest happened to list its requirements in — otherwise two
     * identical stacks resolve to two different image tags and the cache
     * never hits.
     *
     * @param  list<Requirement>  $requirements
     */
    public static function setToken(array $requirements): string
    {
        $tokens = [];
        foreach ($requirements as $requirement) {
            $tokens[$requirement->token()] = true;
        }
        $tokens = array_keys($tokens);
        sort($tokens);

        return implode('_', $tokens);
    }
}
