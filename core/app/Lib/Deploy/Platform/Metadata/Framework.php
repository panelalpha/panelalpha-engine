<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Runtime\Requirement;

/**
 * A framework the project depends on: "which Laravel", unlike {@see Requirement}'s "which PHP".
 * No Laravel dependencies: unit-testable.
 */
final class Framework
{
    /** `$version` is null unless a lockfile resolved it, which is why `$constraint` is kept beside it. */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $constraint,
        public readonly ?string $version,
        public readonly string $source
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'constraint' => $this->constraint,
            'version' => $this->version,
            'source' => $this->source,
        ];
    }

    /**
     * The major version as an integer, or null when nothing resolved it: the one part of a
     * version that reliably changes what a deploy should run.
     */
    public function major(): ?int
    {
        $version = $this->version ?? $this->constraint;
        if (preg_match('/(\d+)/', $version, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }
}
