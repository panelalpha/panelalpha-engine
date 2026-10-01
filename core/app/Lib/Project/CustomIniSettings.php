<?php

namespace App\Lib\Project;

use App\Models\User;
use App\System;
use App\System\Project\PhpHosting;
use Illuminate\Validation\ValidationException;

/**
 * A project's own php.ini directives, one set per installed PHP version.
 */
class CustomIniSettings
{
    public function __construct(private System $system)
    {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function get(User $user, string $phpVersion): array
    {
        $this->requirePhpHosting($user);
        $this->requireInstalledVersion($phpVersion);

        return $user->project($this->system)->php()->getCustomIniSettings($phpVersion);
    }

    /**
     * @param array<string, string> $settings
     */
    public function replace(User $user, string $phpVersion, array $settings): void
    {
        $this->requirePhpHosting($user);
        $this->requireInstalledVersion($phpVersion);

        try {
            // The version is the identity of the set. Other installed
            // versions keep whatever they already have.
            $user->project($this->system)->php()->updateCustomIniSettings($phpVersion, $settings);
        } catch (\Exception $e) {
            throw ValidationException::withMessages([
                'settings' => 'Could not set php.ini directives. ' . $e->getMessage(),
            ]);
        }
    }

    /**
     * The file is mounted into the project's own PHP containers. A dind app
     * runs PHP from its own image, which never reads it.
     */
    private function requirePhpHosting(User $user): void
    {
        if (!$user->project($this->system)->runtime() instanceof PhpHosting) {
            throw ValidationException::withMessages([
                'project' => 'Custom PHP INI settings apply only to PHP hosting projects. '
                    . 'A dind project runs PHP from its own image; set php.ini there.',
            ]);
        }
    }

    private function requireInstalledVersion(string $phpVersion): void
    {
        if (!in_array($phpVersion, $this->system->php()->listAvailablePhpVersions())) {
            throw ValidationException::withMessages([
                'php_version' => 'Invalid value',
            ]);
        }
    }
}
