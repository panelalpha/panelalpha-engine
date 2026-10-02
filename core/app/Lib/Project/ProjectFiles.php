<?php

namespace App\Lib\Project;

use App\Exceptions\NotFoundException;
use App\Lib\Helpers\FileStreamWrapper;
use App\Models\User;
use App\System;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/** File operations on a project that take more than one FileManager call; the API and the CLI share them. */
final class ProjectFiles
{
    /** Store $file in $dir, a path already resolved inside the project. */
    public static function upload(User $user, string $dir, UploadedFile $file): void
    {
        $user->project()->fileManager()->moveUploadedFile($dir, $file);
        if ($file->getClientOriginalName() === '.htaccess') {
            self::htaccessChanged($dir);
        }
    }

    /**
     * A stream path that reads the resolved file $path, or null when there is
     * no such file.
     */
    public static function readablePath(User $user, string $path, ?System $system = null): ?string
    {
        $system ??= new System();
        if (!$system->filesystem()->fileExists($path)) {
            return null;
        }

        FileStreamWrapper::register();
        // The path is confined as a string only; the read runs as root and
        // follows symlinks, so the helper re-checks the resolved file.
        FileStreamWrapper::confineTo($user->project($system)->homeDirPath());

        return 'sudophp://' . $path;
    }

    /** Like readablePath(), but $path is as the caller gave it, inside the project. */
    public static function readablePathOrFail(User $user, string $path): string
    {
        return self::readablePath($user, $user->project()->resolvePath($path))
            ?? throw new NotFoundException("File '{$path}' not found in project '{$user->username}'.");
    }

    /** OpenLiteSpeed reads .htaccess only on a reload. */
    public static function htaccessChanged(string $path): void
    {
        try {
            $system = new System();
            if ($system->webserver()->getCurrentWebserver() === 'openlitespeed') {
                $system->webserver()->scheduleWebserverReloadInBackground();
            }
        } catch (\Exception $e) {
            Log::warning('Failed to schedule OpenLiteSpeed reload after .htaccess change', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }
}
