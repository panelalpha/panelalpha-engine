<?php

namespace App\Lib\Deploy\Platform;

/**
 * Detection logic a `detect` predicate cannot express — walking directories
 * to find a Next.js app inside an unknown workspace layout, for instance.
 *
 * A manifest names a probe under `{"probe": "…"}` and keeps everything else
 * declarative. Probes decide detection only; they never return commands.
 */
interface PlatformProbe
{
    /** Name a manifest references in `{"probe": "..."}`. */
    public function id(): string;

    /**
     * False when the project is not this platform's. True, or an array of
     * extra decision fields to merge into the match, when it is.
     *
     * @return bool|array<string, mixed>
     */
    public function evaluate(ProjectContext $context): bool|array;
}
