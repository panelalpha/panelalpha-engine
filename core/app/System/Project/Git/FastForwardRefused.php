<?php

namespace App\System\Project\Git;

/**
 * Git would not fast-forward (diverged history, or an incoming commit collides with something local) and changed nothing.
 * An {@see Exception}, so a refused pull stays a 422; a caller telling "git said no" from "the pull broke" catches it first.
 */
class FastForwardRefused extends Exception
{
    /**
     * @param  list<string>  $paths  the incoming paths that collide with local ones; empty for diverged history
     */
    public function __construct(string $message, public readonly array $paths = [])
    {
        parent::__construct($message, 422);
    }
}
