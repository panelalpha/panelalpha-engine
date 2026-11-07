<?php

namespace App\System\Project\PhpHosting\WordPress\Migration\Steps;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\SourceUrl;
use RuntimeException;

/**
 * The destination install of an import: the kept domain's user, and its document root plus the source's subdirectory.
 */
trait LocatesDestinationInstall
{
    private function destinationUser(Domain $domain, string $label): User
    {
        $user = $domain->user;
        if (! $user instanceof User) {
            throw new RuntimeException("No user for domain '{$label}'");
        }

        return $user;
    }

    private function destinationPath(WordpressMigration $migration, User $user, Domain $domain): string
    {
        $docRoot = $user->project()->homeDirPath().$domain->getDocumentRoot();
        $subdir = SourceUrl::installSubdirectory($migration->source_url);

        return $subdir === '' ? $docRoot : $docRoot.'/'.$subdir;
    }
}
