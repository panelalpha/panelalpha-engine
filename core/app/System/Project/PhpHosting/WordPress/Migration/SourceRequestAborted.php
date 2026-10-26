<?php

namespace App\System\Project\PhpHosting\WordPress\Migration;

use RuntimeException;

/**
 * A source request stopped because the caller no longer wanted it: the run
 * waiting on it lost its claim on the migration to another run.
 */
final class SourceRequestAborted extends RuntimeException
{
    public static function at(string $url): self
    {
        return new self('Source WordPress request aborted: this run no longer holds the migration ('.$url.')');
    }
}
