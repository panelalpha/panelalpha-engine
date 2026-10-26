<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use App\Models\Domain;
use App\Models\User;

/**
 * What an existing project's docroot holds, read as the account.
 */
final class ProjectDocroot
{
    private const PLACEHOLDER = 'index.html';

    private const WORDPRESS_CORE_MARKER = 'wp-includes/version.php';

    /**
     * True when the docroot has no WordPress core and the install path
     * (docroot or its subdirectory) is missing, empty, or only the placeholder page.
     */
    public function holdsOnlyPlaceholder(User $user, Domain $domain, string $subdir): bool
    {
        $files = $user->project()->fileManager();
        $docRoot = rtrim($user->project()->homeDirPath(), '/').$domain->getDocumentRoot();
        $path = $subdir === '' ? $docRoot : $docRoot.'/'.$subdir;

        if ($files->exists($docRoot.'/'.self::WORDPRESS_CORE_MARKER)) {
            return false;
        }
        if (! $files->exists($path)) {
            return true;
        }

        $names = array_column($files->list($path)['entries'], 'name');

        return array_diff($names, [self::PLACEHOLDER]) === [];
    }
}
