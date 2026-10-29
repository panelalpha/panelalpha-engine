<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\CacheManager\PhpBaseImage;

/**
 * What one runtime at one version resolves to: an image, and how to get it.
 *
 * Not the tag itself — a built image's tag fingerprints the whole recipe, and
 * the recipe lives with whoever assembles it
 * ({@see PhpBaseImage}). This says what to build
 * from and where to put it.
 */
final class RuntimeImageSpec
{
    public function __construct(
        public readonly string $runtime,
        public readonly string $version,
        /** What a pull-only runtime runs, and what a built one builds FROM. */
        public readonly string $from,
        public readonly ?string $repository = null,
        public readonly ?string $stub = null,
        /** The derivative is the runtime: a deploy waits for it rather than using {@see $from}. */
        public readonly bool $runnable = false,
        /**
         * What a *host compile* builds in, when that has to differ from what
         * the app runs in. Null means "same as {@see $from}".
         *
         * Rust is why this exists: `rust:1-slim-bookworm` is the right runtime
         * and the wrong build image, because a host compile runs as the
         * account and cannot install the compiler, pkg-config and headers the
         * slim tag omits. {@see RustRuntime}
         * names the default; a host that points `from` at an image of its own
         * says so here too, or the compile keeps using ours.
         */
        public readonly ?string $buildFrom = null
    ) {}

    /** Can the engine produce this image itself, rather than pull it? */
    public function isBuilt(): bool
    {
        return $this->repository !== null && $this->stub !== null;
    }

    /** `php-8.3`, spelled as {@see Requirement::token()} spells it. */
    public function token(): string
    {
        return $this->runtime.'-'.$this->version;
    }
}
