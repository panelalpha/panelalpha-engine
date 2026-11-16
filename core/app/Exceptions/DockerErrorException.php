<?php

namespace App\Exceptions;

use Exception;

class DockerErrorException extends Exception
{
    private const DAEMON_ERROR = 'Error response from daemon';

    public function isContainerUnavailable(): bool
    {
        return self::meansContainerUnavailable($this->getMessage());
    }

    /** Docker's "Container x is restarting", compose v2's `service "x" is not running`. */
    public static function meansContainerUnavailable(string $message): bool
    {
        return (bool) preg_match('/\b(container|service)\s+\S+\s+is\s+(restarting|not running)\b/i', $message);
    }

    /**
     * What a failed command's output stands for, whichever runner ran it: this when docker answered with an error
     * or the container is not up, a plain failure of the command otherwise.
     */
    public static function orPlainFailure(string $output): Exception
    {
        return self::isDockers($output) ? new self($output) : new Exception($output);
    }

    private static function isDockers(string $output): bool
    {
        return str_contains($output, self::DAEMON_ERROR) || self::meansContainerUnavailable($output);
    }
}
