<?php

namespace App\Lib\Project;

use App\Models\User;
use App\System\Privileged\Exchange;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A local archive put into a project for an archive deploy, and taken out again: written as the account, inside the home
 * but outside ~/project, which the next deploy restores from an earlier failed redeploy before it reads the archive.
 */
class ArchiveDrop
{
    /** The directory inside the account home the archive goes into. */
    public const TARGET_DIR = '/.panelalpha/archives';

    /**
     * Where a create or a source change puts the archive it was sent as an upload or a URL: ~/project, which the
     * import's sync replaces with the archive's own tree, the archive's folder with it.
     */
    public const PROJECT_DIR = '/project';

    /** Starts the random name of the folder an archive is put in. */
    private const DROP_PREFIX = '.deploy-archive-';

    /**
     * Under its own name in a folder of its own, so the log names the file the caller gave and two archives of one name
     * never meet. Copied through the engine's temp directory, since the account-side write needs a readable source.
     *
     * @param  string  $name  the archive's file name, with the format suffix the import reads it by
     * @param  string  $dir  {@see TARGET_DIR}, or {@see PROJECT_DIR} for the archive a create or a source change was sent
     * @return string the archive's path inside the project, as zip_path takes it
     */
    public function put(User $user, string $file, string $name, string $dir = self::TARGET_DIR): string
    {
        $folder = $dir.'/'.self::DROP_PREFIX.Str::lower(Str::random(12));
        $copy = Exchange::file('pa-archive-');
        copy($file, $copy);

        try {
            $archive = new UploadedFile($copy, self::fileName($name), null, null, true);
            ProjectFiles::upload($user, $folder, $archive);

            return $folder.'/'.$archive->getClientOriginalName();
        } finally {
            @unlink($copy);
        }
    }

    /** A control character, which the file manager refuses in a path, and a backslash, which UploadedFile reads as a folder, become `_`. */
    private static function fileName(string $name): string
    {
        return (string) preg_replace('~[\x00-\x1F\x7F\\\\]~', '_', $name);
    }

    /** Removed whatever the deploy did; nothing else reads it once the deploy has imported it. */
    public function remove(User $user, string $zipPath): void
    {
        $files = $user->project()->fileManager();
        $drop = self::dropOf($zipPath);
        if ($files->exists($drop)) {
            $files->remove($drop, recursive: $drop !== $zipPath);
        }
    }

    /** An archive goes with the folder it was put in; a path in no such folder goes alone. */
    private static function dropOf(string $zipPath): string
    {
        $folder = dirname($zipPath);

        return str_starts_with(basename($folder), self::DROP_PREFIX) ? $folder : $zipPath;
    }

    /** {@see remove()} once the deploy has ended, which a failure to remove it must not turn into a different outcome. */
    public function discard(User $user, string $zipPath): void
    {
        try {
            $this->remove($user, $zipPath);
        } catch (\Exception $e) {
            Log::warning("The archive {$zipPath} could not be removed from project {$user->username}: {$e->getMessage()}");
        }
    }
}
