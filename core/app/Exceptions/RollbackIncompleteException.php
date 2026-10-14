<?php

namespace App\Exceptions;

/**
 * A step failed and undoing it failed too: the target was left partly changed.
 * Carries the original failure as its message and cause, and each step that
 * could not be undone.
 */
class RollbackIncompleteException extends \RuntimeException
{
    /**
     * @param list<string> $failures
     */
    public function __construct(\Throwable $cause, public readonly array $failures)
    {
        parent::__construct($cause->getMessage(), 0, $cause);
    }
}
