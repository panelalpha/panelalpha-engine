<?php

namespace App\Lib\Upload;

use App\Lib\Http\GuardedDownload;
use App\Lib\Project\ArchiveDrop;
use App\Models\User;
use InvalidArgumentException;

/**
 * An archive a create or attach named by URL, downloaded through the outbound guard into the project for an archive
 * deploy. Its format is read from its first bytes, as a download URL often ends in no file name.
 */
class ArchiveFetch
{
    /** Longest URL segment kept as the archive's name, well inside the 255 bytes a file name may take, a suffix added. */
    private const NAME_MAX_BYTES = 200;

    /**
     * @return string the archive's path in the project, as an archive deploy takes it
     *
     * @throws InvalidArgumentException when the URL is refused or holds no zip or tar.gz
     */
    public function into(User $user, string $url): string
    {
        $temp = (string) tempnam(sys_get_temp_dir(), 'pa-archive-url-');
        try {
            GuardedDownload::fetch($url, $temp);
            $suffix = self::sniffedSuffix($temp)
                ?? throw new InvalidArgumentException("The file at {$url} is not a .zip or .tar.gz archive.");

            return app(ArchiveDrop::class)->put($user, $temp, self::nameOf($url, $suffix), ArchiveDrop::PROJECT_DIR);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * The URL's last path segment, decoded, with the sniffed format's suffix added when the segment does not say that
     * format: the import reads the format by the name. One that is empty or too long is `archive`.
     */
    private static function nameOf(string $url, string $suffix): string
    {
        $segment = basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $name = in_array($segment, ['', '.', '..'], true) || strlen($segment) > self::NAME_MAX_BYTES ? 'archive' : $segment;
        $named = Uploads::suffixOf($name);

        return $named === $suffix || ($suffix === '.tar.gz' && $named === '.tgz') ? $name : $name.$suffix;
    }

    private static function sniffedSuffix(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 4);

        return match (true) {
            str_starts_with($head, "PK\x03\x04") => '.zip',
            str_starts_with($head, "\x1f\x8b") => '.tar.gz',
            default => null,
        };
    }
}
