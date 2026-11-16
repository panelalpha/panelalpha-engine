<?php

namespace App\Lib\Project;

use Illuminate\Validation\ValidationException;

/**
 * The example `system:example:create` makes. A repository one is deployed on the queue; WordPress is deployed in this
 * process first, since WP-CLI runs as the account, which has its UID and GID only after its deploy.
 */
final class ExampleProjectCreator
{
    public function __construct(private readonly ProjectCreator $creator) {}

    /** @throws ValidationException */
    public function create(NewProjectInput $input): void
    {
        if (! empty($input->params['git_repo'])) {
            $this->creator->queue($input);

            return;
        }

        $this->creator->create($input);
    }
}
