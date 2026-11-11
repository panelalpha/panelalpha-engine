<?php

namespace App\Lib\Project\Create;

use App\Lib\Project\NewProjectInput;

/**
 * A validated PUT /projects/{username}/source: the source, git fields and build options, and whether
 * `build.env_vars` was sent at all (null clears them).
 */
final class SourceSetInput
{
    public function __construct(
        public readonly SourceSpec $source,
        public readonly NewProjectInput $input,
        public readonly bool $setsEnvVars,
    ) {}
}
