<?php

namespace App\Lib\Domains;

use App\Models\Domain;
use App\System;
use Illuminate\Validation\ValidationException;

/**
 * Switches a domain to another installed PHP version and rebuilds what serves it.
 */
class DomainPhpVersion
{
    /**
     * @throws ValidationException when the version is not installed
     */
    public function set(Domain $domain, string $version): void
    {
        $system = new System();
        $versions = $system->php()->listAvailablePhpVersions();

        if (!in_array($version, $versions)) {
            throw ValidationException::withMessages([
                'version' => 'Invalid value',
            ]);
        }

        $domain->setPhpVersion($version);
        $domain->save();
        $domain->projectDomain()->rebuild();

        $domain->getUser()->project()->syncPhpHandlersScripts();
        $domain->getUser()->project()->syncServices();
        // Nothing tells the caller about a pending reload; reloadWebserver() logs it.
        $system->reloadWebserver();
    }
}
