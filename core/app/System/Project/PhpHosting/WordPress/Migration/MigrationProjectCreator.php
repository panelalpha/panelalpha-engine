<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

/**
 * Creates the destination hosting project for a WordPress migration.
 * Production provisions and deploys in-process; tests inject a fake.
 */
interface MigrationProjectCreator
{
    /**
     * Create an empty classic project. With a domain it gets exactly that name
     * and no tunnel; without one, DomainAllocator allocates a panelalpha.online
     * name with tunnel=panelalpha. Returns only after the hosting container is ready.
     *
     * @return string the project's domain
     */
    public function create(string $username, ?string $domain = null): string;
}
