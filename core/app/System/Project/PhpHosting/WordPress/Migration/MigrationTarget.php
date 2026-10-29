<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * Where an import lands: the domain the site keeps (null when a
 * panelalpha.online name is still to be allocated), the project it goes into
 * (null when the import names a new one itself), whether the import creates
 * that project, and whether the caller fixed the domain (a `domain`, or an
 * existing project's own) rather than leaving it to the source's home.
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
