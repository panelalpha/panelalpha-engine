<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * A toolchain image (gradle, maven) with node, npm, corepack, yarn 1 and pnpm copied in, for a host build that
 * shells out to npm: its single `docker run` has no Dockerfile to add Node to. The app still runs the stock image.
 */
final class NodeBuildImage
{
    use FingerprintedTag;

    public const REPOSITORY = 'panelalpha/build-node';

    /**
     * Null when either reference is not one we would put in a Dockerfile; the Dockerfile fingerprint in the tag
     * keeps another toolchain and Node pair's image from being served.
     */
    public static function tag(string $image, string $nodeImage): ?string
    {
        $dockerfile = self::dockerfile($image, $nodeImage);
        if ($dockerfile === null) {
            return null;
        }

        return self::fingerprintedTag($image, $dockerfile);
    }

    /**
     * Copies the three directories the official Node images keep node in; the RUN fails this image, not the build,
     * when node cannot start on the toolchain's libc. corepack (not in 25+) puts pnpm on PATH for node-gradle.
     */
    public static function dockerfile(string $image, string $nodeImage): ?string
    {
        $image = trim($image);
        $nodeImage = trim($nodeImage);
        if (! ImageTransfer::isSafeImageRef($image) || ! ImageTransfer::isSafeImageRef($nodeImage)) {
            return null;
        }

        return implode("\n", [
            "FROM {$nodeImage} AS node",
            "FROM {$image}",
            'COPY --from=node /usr/local/bin/ /usr/local/bin/',
            'COPY --from=node /usr/local/lib/node_modules/ /usr/local/lib/node_modules/',
            'COPY --from=node /opt/ /opt/',
            'RUN node --version && npm --version && { ! command -v corepack >/dev/null || corepack enable pnpm; }',
            '',
        ]);
    }
}
