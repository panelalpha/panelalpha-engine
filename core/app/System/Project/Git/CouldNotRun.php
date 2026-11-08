<?php

namespace App\System\Project\Git;

use App\Exceptions\DockerErrorException;

/**
 * Git never ran: the project's container is not running, or docker refused the exec. An {@see Exception}, a 503 as
 * a stopped container is, which says nothing about the checkout, so nothing reads it as "no repository there".
 */
class CouldNotRun extends Exception
{
    /** runc refusing the exec, which the docker CLI prints without the daemon's own prefix. */
    private const EXEC_REFUSED = '/\bOCI runtime exec failed\b/i';

    public static function inContainer(string $dockerError, bool $notRunning): self
    {
        $why = $notRunning
            ? 'the project\'s container is not running'
            : 'docker would not run it in the project\'s container';

        return new self('Git could not run: '.$why.' ('.trim($dockerError).')', 503);
    }

    /** A failed command's output that is docker's, not git's; null when git ran and failed. */
    public static function fromOutput(string $output): ?self
    {
        if (DockerErrorException::meansContainerUnavailable($output)) {
            return self::inContainer($output, true);
        }

        return preg_match(self::EXEC_REFUSED, $output) === 1 ? self::inContainer($output, false) : null;
    }
}
