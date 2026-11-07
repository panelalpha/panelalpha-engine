<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * Where an import lands: the domain (null until a panelalpha.online name is allocated), the project (null when the
 * import names a new one), whether it creates it, and whether the caller fixed the domain rather than the source's home.
 */
final class MigrationTarget
{
    public function __construct(
        public readonly ?string $domain,
        public readonly ?string $project,
        public readonly bool $createsProject,
        public readonly bool $domainGiven = false,
    ) {}

    public static function newProject(?string $domain, ?string $projectName, bool $domainGiven = false): self
    {
        return new self($domain, $projectName, true, $domainGiven);
    }

    public static function existingProject(string $project, string $domain): self
    {
        return new self($domain, $project, false, true);
    }
}
