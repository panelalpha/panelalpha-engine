<?php

namespace App\Lib\Deploy\CacheManager;

/** The tag of a host build image: its repository, a slug of the toolchain image and a Dockerfile fingerprint. */
trait FingerprintedTag
{
    private static function fingerprintedTag(string $image, string $dockerfile): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower(trim($image))), '-.');

        return self::REPOSITORY.':'.substr($slug, 0, 80).'-pa'.substr(sha1($dockerfile), 0, 8);
    }
}
