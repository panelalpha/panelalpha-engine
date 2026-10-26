<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\Domain;
use App\Models\User;
use App\Models\WordpressMigration;

/**
 * The project an import works in, and where in it the site goes.
 */
final class ImportedProject
{
    /**
     * The project this import created: named on the record before it was
     * created, and on the import's domain once that is known. A project of
     * that name on another domain is someone else's.
     */
    public static function createdBy(WordpressMigration $migration): ?User
    {
        if (! $migration->created_project || ! is_string($migration->target_project) || $migration->target_project === '') {
            return null;
        }

        $user = User::findByUsername($migration->target_project);
        if ($user === null) {
            return null;
        }

        $label = $migration->target_label;

        return $label === null || $label === '' || $user->domain === $label ? $user : null;
    }

    /** The project and main domain the site is imported onto, once both exist. */
    public static function domainOf(WordpressMigration $migration): ?Domain
    {
        $label = $migration->target_label;

        return is_string($label) && $label !== '' ? Domain::findByName($label) : null;
    }

    public static function installPath(User $user, Domain $domain, string $sourceUrl): string
    {
        $docRoot = rtrim($user->project()->homeDirPath(), '/').$domain->getDocumentRoot();
        $subdir = SourceUrl::installSubdirectory($sourceUrl);

        return $subdir === '' ? $docRoot : $docRoot.'/'.$subdir;
    }
}
