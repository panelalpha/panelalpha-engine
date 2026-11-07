<?php

namespace App\Lib\Deploy\Platform\Runtime;

/**
 * One toolchain a project needs, at the version it needs, and why. No Laravel dependencies:
 * unit-testable.
 */
final class Requirement
{
    /** Must be present in the image the application runs in. */
    public const ROLE_RUNTIME = 'runtime';

    /** Needed to build the application, never at runtime. Gets its own stage. */
    public const ROLE_BUILD = 'build';

    /** `$source` names the constraint that decided the version, often a locked dependency's `require.php`. */
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
     * Canonical token for a set: sorted, so the same set gives the same image tag whatever order
     * the manifest listed it in, then de-duplicated and joined.
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
