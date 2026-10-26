<?php

namespace App\Lib\Domains;

use App\Models\Domain;
use App\System;
use App\System\Project\PhpHosting;
use Illuminate\Validation\ValidationException;

/**
 * Switches a domain to another installed PHP version and rebuilds what serves it.
 */
class DomainPhpVersion
{
    /**
     * The webserver reload at the end reports nothing back; reloadWebserver() logs a pending one.
     *
     * @throws ValidationException when the version is not installed or the project is not PHP hosting
     */
    public function set(Domain $domain, string $version): void
    {
        $system = new System;
        $this->ensureInstalled($system, $version);
        $this->ensurePhpHosting($system, $domain);

        $domain->setPhpVersion($version);
        $domain->save();
        $domain->projectDomain()->rebuild();

        $domain->getUser()->project()->syncPhpHandlersScripts();
        $domain->getUser()->project()->syncServices();
        $system->reloadWebserver();
    }

    /**
     * @throws ValidationException
     */
    private function ensureInstalled(System $system, string $version): void
    {
        $versions = $system->php()->listAvailablePhpVersions();

        if (! in_array($version, $versions)) {
            throw ValidationException::withMessages([
                'version' => 'Invalid value',
            ]);
        }
    }

    /**
     * A dind app runs PHP from its own image, so a stored version would change nothing it reads.
     *
     * @throws ValidationException
     */
    private function ensurePhpHosting(System $system, Domain $domain): void
    {
        if (! $domain->getUser()->project($system)->runtime() instanceof PhpHosting) {
            throw ValidationException::withMessages([
                'version' => 'The PHP version applies only to PHP hosting projects. '
                    .'A dind project runs PHP from its own image; set it there.',
            ]);
        }
    }
}
