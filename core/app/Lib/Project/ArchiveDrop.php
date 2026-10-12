<?php

namespace App\Lib\Project;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * A local archive put into a project for an archive deploy, and taken out
 * again. It is written as the account, inside the home confinement, but
 * outside ~/project: a redeploy that failed earlier leaves the checkout it
 * moved aside, and the next deploy puts that back over ~/project before it
 * reads the archive.
 */
class ArchiveDrop
{
    /** The directory inside the account home the archive goes into. */
    public const TARGET_DIR = '/.panelalpha/archives';

    /**
     * Copied into the engine's temp directory first: the account-side write
     * makes its source readable, and the operator's own file keeps its mode.
     *
     * @param string $extension the format suffix the import reads the archive by
     * @return string the archive's path inside the project, as zip_path takes it
     */
    public function put(User $user, string $file, string $extension): string
    {
        $name = '.deploy-archive-' . Str::lower(Str::random(12)) . $extension;
        $copy = (string) tempnam(sys_get_temp_dir(), 'pa-archive-');
        copy($file, $copy);

        try {
            ProjectFiles::upload($user, self::TARGET_DIR, new UploadedFile($copy, $name, null, null, true));
        } finally {
            @unlink($copy);
        }

        return self::TARGET_DIR . '/' . $name;
    }

    /** Removed whatever the deploy did; nothing else reads it once the deploy has imported it. */
    public function remove(User $user, string $zipPath): void
    {
        $files = $user->project()->fileManager();
        if ($files->exists($zipPath)) {
            $files->remove($zipPath);
        }
    }
}
