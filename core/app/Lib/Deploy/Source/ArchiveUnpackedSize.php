<?php

namespace App\Lib\Deploy\Source;

/**
 * How large an archive really is once unpacked, measured rather than believed.
 *
 * A zip states each member's uncompressed size twice -- in the central
 * directory and in the local file header -- and both are written by whoever
 * built the archive. Neither `unzip -Z` nor `ZipArchive::statIndex()` checks
 * them against the data, so an archive can declare anything:
 *
 *     50 MB of zeros, deflated to 51 KB, size fields patched to 10:
 *       unzip -Z            ->         10 bytes
 *       ZipArchive statIndex->         10 bytes
 *       the stream itself   -> 52,428,800 bytes
 *
 * So the declaration is a hint worth rejecting on when it is already too big,
 * and nothing more. The number that decides is the one counted off the
 * decompression stream, which is what this class does.
 *
 * Counting stops the moment the cap is crossed, so a bomb is paid for in the
 * bytes it takes to exceed the limit and not in the bytes it claims to hold.
 *
 * No Laravel dependencies -- unit-testable.
 */
final class ArchiveUnpackedSize
{
    private const CHUNK = 262144;

    /**
     * Below this, nothing is worth refusing, whatever the ratio.
     */
    private const RATIO_FLOOR_BYTES = 64 * 1024 * 1024;

    /**
     * Past the floor, an archive that has already yielded this many times its
     * own size is a bomb rather than a well-compressed upload. Real source
     * trees land in single digits; logs and other repetitive text reach a few
     * dozen. This only shortens the work -- the cap above is what decides.
     */
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
     * Every member's stream, read and discarded. `getStreamIndex()` rather than
     * `getStream()` because a member name is the archive author's to choose and
     * need not be something a lookup can round-trip.
     */
    private static function countZip(string $path, int $maxBytes, int $archiveBytes): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not read the uploaded archive.');
        }

        $total = 0;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stream = $zip->getStreamIndex($i);
                if ($stream === false) {
                    // A directory entry, or a member with no readable stream.
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

    /**
     * The decompressed tar stream, headers and padding included. That is a few
     * per cent above the sum of the members and never below it, which is the
     * direction a limit can afford to be wrong in.
     */
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
     * @param resource $stream
     */
    private static function drain(
        $stream,
        int $total,
        int $maxBytes,
        int $archiveBytes,
        bool $gz = false
    ): int {
        while (!($gz ? gzeof($stream) : feof($stream))) {
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
