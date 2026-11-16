<?php

namespace App\Lib\Project\Create;

use App\Lib\Project\NewProjectInput;

/**
 * A validated create: the flat create it maps onto and, for the typed body, the source whose type decides how the
 * project gets its first deploy.
 */
final class ProjectSpec
{
    public function __construct(
        public readonly NewProjectInput $input,
        public readonly ?SourceSpec $source = null,
    ) {}

    public static function flat(NewProjectInput $input): self
    {
        return new self($input);
    }
}
