<?php

namespace App\System\Services;

use App\System as EngineSystem;

/**
 * Host PHP version catalog from the project template.
 */
class Php
{
    /** @var array<string> */
    private ?array $availablePhpVersions = null;

    public function __construct(
        private EngineSystem $system,
    ) {}

    /** The first version the host template lists, or null when it lists none. */
    public function defaultPhpVersion(): ?string
    {
        $versions = $this->listAvailablePhpVersions();
        if ($versions === []) {
            return null;
        }

        return reset($versions);
    }

    /**
     * @return array<string>
     */
    public function listAvailablePhpVersions(): array
    {
        if ($this->availablePhpVersions !== null) {
            return $this->availablePhpVersions;
        }

        $versionsFile = $this->system->projectFilesTemplateDirPath().'/php/versions-available';
        if (! is_file($versionsFile)) {
            return [];
        }
        $versions = file($versionsFile, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);

        return $this->availablePhpVersions = $versions === false ? [] : $versions;
    }
}
