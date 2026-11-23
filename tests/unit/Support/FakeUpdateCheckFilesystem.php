<?php

namespace Tests\Support;

use App\System\Filesystem;

/** The engine's files as far as the last update run goes: there is none. */
final class FakeUpdateCheckFilesystem extends Filesystem
{
    public function __construct() {}

    public function directoryExists(string $path): bool
    {
        return false;
    }
}
