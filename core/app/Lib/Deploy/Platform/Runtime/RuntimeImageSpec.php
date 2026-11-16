<?php

namespace App\Lib\Deploy\Platform\Runtime;

use App\Lib\Deploy\CacheManager\PhpBaseImage;

/**
 * What one runtime at one version resolves to: what to build from and where to put it, not the tag,
 * which fingerprints a recipe kept by whoever assembles it ({@see PhpBaseImage}).
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
         * What a *host compile* builds in when not {@see $from} (a slim {@see RustRuntime} has no compiler);
         * a host pointing `from` at its own image must set this too, or the compile keeps using ours.
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
