<?php

namespace App\System\Project\Git;

/**
 * Git would not fast-forward, and said why: the history diverged, or an
 * incoming commit collides with something local. Nothing was changed.
 *
 * A subclass of {@see Exception} so every caller that already treats a refused
 * pull as a 422 keeps doing so; a caller that must tell "git said no" from "the
 * pull broke" (a Deploy Hook delivery) catches this one first.
 */
class FastForwardRefused extends Exception
{
    /**
     * @param list<string> $paths the incoming paths that collide with local ones; empty for diverged history
     */
    public function __construct(string $message, public readonly array $paths = [])
    {
        parent::__construct($message, 422);
    }
}
