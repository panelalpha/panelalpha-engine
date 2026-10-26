<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * The target fields as the caller sent them, before
 * {@see MigrationTargetResolver} decides what they mean.
 */
final class TargetChoice
{
    public function __construct(
        public readonly ?string $domain = null,
        public readonly ?string $project = null,
        public readonly ?string $projectName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        return new self(
            self::stringOrNull($validated['domain'] ?? null),
            self::stringOrNull($validated['project'] ?? null),
            self::stringOrNull($validated['project_name'] ?? null),
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
