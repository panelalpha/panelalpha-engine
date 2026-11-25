<?php

namespace App\Exceptions;

/** A host compile made what `$recipe` serves, not what the deploy's recipe does; that recipe keeps the build. See HostCompile. */
class BuildHandedOverException extends \Exception
{
    public function __construct(
        public readonly string $recipe,
        public readonly string $from,
        public readonly string $marker
    ) {
        parent::__construct("The build wrote {$marker}: it is a {$recipe} build, not a {$from} one");
    }
}
