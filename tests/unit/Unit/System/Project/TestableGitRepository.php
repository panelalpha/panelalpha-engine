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
        ?string $relativePath = null,
        ?string $absolutePathOverride = null,
    ) {
        parent::__construct($project, $relativePath);
        $this->execute = $execute;
        if ($absolutePathOverride !== null) {
            $this->absolutePath = $absolutePathOverride;
        }
    }
}
