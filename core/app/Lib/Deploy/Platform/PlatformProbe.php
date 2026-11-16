<?php

namespace App\Lib\Deploy\Platform;

/**
 * Detection logic a `detect` predicate cannot express, such as finding a Next.js app in an unknown
 * workspace layout; a manifest names it under `{"probe": "…"}`, and it never returns commands.
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
