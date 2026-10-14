<?php

namespace App\Lib\Deploy\Source;

/**
 * A remote whose refs could not be listed: missing, private, or unreachable.
 *
 * The outcome names which, so the API boundary can map it to a status and a
 * stable problem code.
 */
final class GitRefsUnavailableException extends \RuntimeException
{
    public const NOT_FOUND = 'repository_not_found';

    public const AUTHENTICATION_FAILED = 'authentication_failed';

    public const UNREACHABLE = 'remote_unreachable';

    public function __construct(public readonly string $outcome, string $message)
    {
        parent::__construct($message);
    }
}
