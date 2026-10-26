<?php

namespace App\Lib\Deploy\Platform\Metadata;

use App\Lib\Deploy\Platform\Runtime\Requirement;

/**
 * A framework the project depends on, at the version it depends on.
 *
 * Separate from {@see Requirement}, which
 * answers "which PHP goes in the image". This answers "which Laravel is this",
 * and the two are unrelated: a project can pin PHP 8.3 and run Laravel 9 or
 * Laravel 12 on it, and only the second decides whether `php artisan optimize`
 * is a sensible thing to run.
 *
 * `version` is the resolved one when a lockfile could supply it and null when
 * only a range was declared, which is why `constraint` is kept alongside
 * rather than being overwritten by it. Reporting `^11.0` as though it were an
 * installed version would be a guess dressed as a fact.
 *
 * No Laravel dependencies — unit-testable.
 */
final class Framework
{
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
     * The major version as an integer, or null when nothing resolved it.
     *
     * The one part of a version that reliably changes what a deploy should
     * run. Whoever generates a per-project manifest wants "Laravel 11", not
     * "11.31.0".
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
