<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Dind;
use App\System\Project\Dind\Source\GitRepository;

/**
 * @internal
 */
final class TestableGitRepository extends GitRepository
{
    use FakeGitExecution;

    /**
     * @param (callable(list<string>, ?string, int): string)|FakeGitRunner $execute
     */
    public function __construct(
        Dind $project,
        $execute,
    ) {
        parent::__construct($project);
        $this->execute = $execute;
    }
}
