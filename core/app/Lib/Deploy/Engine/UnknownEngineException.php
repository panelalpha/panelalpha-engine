<?php

namespace App\Lib\Deploy\Engine;

/**
 * A container engine was asked for by a name nothing is registered under —
 * almost always a typo in `DEPLOY_ENGINE`, or an engine whose registration
 * has not run.
 */
class UnknownEngineException extends \InvalidArgumentException
{
    /**
     * @param  list<string>  $known
     */
    public static function forName(string $name, array $known): self
    {
        return new self(sprintf(
            "Unknown container engine '%s'. Registered: %s",
            $name,
            $known === [] ? '(none)' : implode(', ', $known)
        ));
    }
}
