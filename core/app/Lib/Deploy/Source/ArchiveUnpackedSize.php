<?php

namespace App\Lib\Deploy\Source;

/**
 * How large an archive really is once unpacked, counted off the decompression stream: a zip's declared
 * sizes are the author's to forge. Counting stops the moment the cap is crossed.
 */
final class ArchiveUnpackedSize
{
    private const CHUNK = 262144;

    /** Below this, nothing is worth refusing, whatever the ratio. */
    private const RATIO_FLOOR_BYTES = 64 * 1024 * 1024;

    /** Past the floor, this many times its own size is a bomb; it only shortens the work, the cap decides. */
    private const MAX_RATIO = 200;

    /**
     * @throws \InvalidArgumentException when the archive unpacks past $maxBytes
     * @throws \RuntimeException when the archive cannot be read at all
     */
    public static function assertWithin(
        string $path,
        bool $isZip,
        int $maxBytes = ArchiveSafety::MAX_UNCOMPRESSED_BYTES
    ): void {
        $archiveBytes = @filesize($path);
        if ($archiveBytes === false) {
            throw new \RuntimeException('Could not read the uploaded archive.');
        }

        $counted = $isZip
            ? self::countZip($path, $maxBytes, $archiveBytes)
            : self::countGzip($path, $maxBytes, $archiveBytes);

        if ($counted > $maxBytes) {
            throw new \InvalidArgumentException(sprintf(
                'Archive unpacks to more than %d MB, which is more than this server accepts.',
                intdiv($maxBytes, 1024 * 1024)
            ));
        }
    }

    /**
     * Every member's stream, read and discarded, by index since a member name need not round-trip.
     * A directory entry, or a member with no readable stream, has none.
     */
    private static function countZip(string $path, int $maxBytes, int $archiveBytes): int
    {
        $zip = new \ZipArchive;
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not read the uploaded archive.');
        }

        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) {
                    continue;
                }

                try {
                    $total = self::drain($stream, $total, $maxBytes, $archiveBytes);
                } finally {
                    fclose($stream);
                }

                if ($total > $maxBytes) {
                    return $total;
                }
            }
        } finally {
            $zip->close();
        }

        return $total;
    }

    /** The decompressed tar stream, headers and padding included: never below the sum of the members. */
    private static function countGzip(string $path, int $maxBytes, int $archiveBytes): int
    {
        $stream = @gzopen($path, 'rb');
        if ($stream === false) {
            throw new \RuntimeException('Could not read the uploaded archive.');
        }

        try {
            return self::drain($stream, 0, $maxBytes, $archiveBytes, true);
        } finally {
            gzclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private static function drain(
        $stream,
        int $total,
        int $maxBytes,
        int $archiveBytes,
        bool $gz = false
    ): int {
        while (! ($gz ? gzeof($stream) : feof($stream))) {
            $chunk = $gz ? gzread($stream, self::CHUNK) : fread($stream, self::CHUNK);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $total += strlen($chunk);
            if ($total > $maxBytes) {
                return $total;
            }
            if (self::isBomb($total, $archiveBytes)) {
                return $maxBytes + 1;
            }
        }

        return $total;
    }

    private static function isBomb(int $total, int $archiveBytes): bool
    {
        return $total > self::RATIO_FLOOR_BYTES
            && $archiveBytes > 0
            && $total > $archiveBytes * self::MAX_RATIO;
    }
}
