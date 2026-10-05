<?php

namespace App\Lib\Deploy\CacheManager;

/**
 * A host build image with Node copied in: the recipe's own toolchain image
 * (gradle, maven) plus node, npm, npx, corepack, yarn 1 and pnpm from a Node image.
 *
 * For a JVM build that shells out to npm. It runs in a single `docker run` of
 * the toolchain image on the host, which has no Dockerfile to add Node to, so
 * the host builds this once and every such build borrows it. Only the build
 * uses it; the container still runs the stock image.
 *
 * The tag carries a fingerprint of the Dockerfile, so a different toolchain
 * or Node image never gets served an image built from another pair.
 */
final class NodeBuildImage
{
    public const REPOSITORY = 'panelalpha/build-node';

    /** Null when either reference is not one we would put in a Dockerfile. */
    public static function tag(string $image, string $nodeImage): ?string
    {
        $dockerfile = self::dockerfile($image, $nodeImage);
        if ($dockerfile === null) {
            return null;
        }
        $slug = trim((string) preg_replace('/[^a-z0-9._-]+/', '-', strtolower(trim($image))), '-.');

        return self::REPOSITORY . ':' . substr($slug, 0, 80) . '-pa' . substr(sha1($dockerfile), 0, 8);
    }

    /**
     * The official Node images keep node and its tools in these three
     * directories. The RUN fails this image, not the deploy's build, when the
     * copied node cannot start on the toolchain image's libc. pnpm ships only
     * as a corepack shim, which the node-gradle plugin's PnpmTask needs on
     * PATH (halo); a Node without corepack (25+) just goes without it.
     */
    public static function dockerfile(string $image, string $nodeImage): ?string
    {
        $image = trim($image);
        $nodeImage = trim($nodeImage);
        if (!ImageTransfer::isSafeImageRef($image) || !ImageTransfer::isSafeImageRef($nodeImage)) {
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
