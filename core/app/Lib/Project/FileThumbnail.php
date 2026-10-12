<?php

namespace App\Lib\Project;

use App\Exceptions\ProblemException;
use App\Models\User;
use App\System;
use Imagick;
use ImagickException;

/**
 * A webp thumbnail of a project image, for the file-manager grid.
 *
 * The source is read as the account through the same confined stream a
 * download uses, so the engine never opens a user-controlled path as its own
 * user. The coder is chosen from the file's own magic bytes and forced, so
 * ImageMagick is never the one deciding how to read the bytes; the pixel count
 * comes from the header before the pixels are there to exhaust memory with.
 */
final class FileThumbnail
{
    /** Largest source file accepted, in bytes. */
    public const MAX_SOURCE_BYTES = 50 * 1024 * 1024;

    /** Largest source image accepted, in pixels. */
    public const MAX_SOURCE_PIXELS = 50 * 1000 * 1000;

    /** Quality the grid's thumbnails are encoded at. */
    public const WEBP_QUALITY = 80;

    private const UNSUPPORTED_MESSAGE = 'The file is not an image this endpoint can thumbnail (png, jpeg, gif or webp).';

    private const TOO_LARGE_MESSAGE = 'The image is too large to thumbnail (over 50 MB or 50 megapixels).';

    private const MEMORY_BYTES = 256 * 1024 * 1024;

    private const MAP_BYTES = 512 * 1024 * 1024;

    private const TIME_SECONDS = 30;

    public function __construct(private readonly ?System $system = null)
    {
    }

    /** The ETag of this thumbnail, or null when the account has no such readable file. */
    public function etag(User $user, string $path, int $width, int $height, string $fit): ?string
    {
        $source = $this->source($user, $path);
        if ($source === null) {
            return null;
        }
        self::assertSourceSize((int) $source['stat']['size']);

        return sha1(implode("\n", [
            $source['stat']['path'],
            $source['stat']['mtime'],
            $source['stat']['size'],
            $width,
            $height,
            $fit,
        ]));
    }

    /** The webp bytes, or null when the account has no such readable file. */
    public function render(User $user, string $path, int $width, int $height, string $fit): ?string
    {
        $source = $this->source($user, $path);
        if ($source === null) {
            return null;
        }

        $bytes = $this->readSource($source);
        if ($bytes === null) {
            return null;
        }

        $format = self::sniffFormat($bytes);
        if ($format === null) {
            throw self::unsupported();
        }

        $this->assertDecodable($bytes, $format);
        $image = $this->decode($bytes, $format);
        try {
            return $this->thumbnail($image, $width, $height, $fit);
        } finally {
            $image->clear();
        }
    }

    /**
     * The readable stream and the account's own stat, or null when there is no
     * such file the account can read.
     *
     * @return array{stream: string, stat: array<string, mixed>}|null
     */
    private function source(User $user, string $path): ?array
    {
        $stream = ProjectFiles::readablePath($user, $path, $this->system);
        if ($stream === null) {
            return null;
        }

        return [
            'stream' => $stream,
            'stat' => $user->project($this->system)->fileManager()->stat($path),
        ];
    }

    /**
     * The source bytes, capped: a file that grew since the stat is refused
     * rather than read whole.
     *
     * @param array{stream: string, stat: array<string, mixed>} $source
     */
    private function readSource(array $source): ?string
    {
        self::assertSourceSize((int) $source['stat']['size']);

        $bytes = @file_get_contents($source['stream'], false, null, 0, self::MAX_SOURCE_BYTES + 1);
        if ($bytes === false) {
            return null;
        }
        self::assertSourceSize(strlen($bytes));

        return $bytes;
    }

    private function assertDecodable(string $bytes, string $format): void
    {
        self::configureLimits();
        $probe = new Imagick();
        $probe->setFormat($format);
        try {
            $probe->pingImageBlob($bytes);
            self::assertPixels($probe->getImageWidth(), $probe->getImageHeight());
        } catch (ImagickException) {
            throw self::unsupported();
        } finally {
            $probe->clear();
        }
    }

    private function decode(string $bytes, string $format): Imagick
    {
        $image = new Imagick();
        $image->setFormat($format);
        try {
            $image->readImageBlob($bytes);
            $image->setIteratorIndex(0);

            return $image->getImage();
        } catch (ImagickException) {
            throw self::unsupported();
        } finally {
            $image->clear();
        }
    }

    private function thumbnail(Imagick $image, int $width, int $height, string $fit): string
    {
        $image->autoOrient();
        if ($fit === 'cover') {
            $image->cropThumbnailImage($width, $height);
        } else {
            $image->thumbnailImage($width, $height, true);
        }
        $image->setImageFormat('webp');
        $image->setImageCompressionQuality(self::WEBP_QUALITY);
        $image->stripImage();

        return $image->getImageBlob();
    }

    /**
     * The coder for a supported image, taken from its own magic bytes; null
     * for anything else. Forcing this is what keeps ImageMagick's own format
     * detection - and the coders it can reach, SVG included - out of the path.
     */
    private static function sniffFormat(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpeg';
        }
        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return 'gif';
        }
        if (str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }

        return null;
    }

    private static function assertPixels(int $width, int $height): void
    {
        if ($width * $height > self::MAX_SOURCE_PIXELS) {
            throw ProblemException::one('path', 'image_too_large', self::TOO_LARGE_MESSAGE);
        }
    }

    private static function assertSourceSize(int $bytes): void
    {
        if ($bytes > self::MAX_SOURCE_BYTES) {
            throw ProblemException::one('path', 'image_too_large', self::TOO_LARGE_MESSAGE);
        }
    }

    private static function unsupported(): ProblemException
    {
        return ProblemException::one('path', 'unsupported_image', self::UNSUPPORTED_MESSAGE);
    }

    private static function configureLimits(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::MEMORY_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, self::MAP_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, self::MAX_SOURCE_PIXELS);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, self::TIME_SECONDS);
    }
}
