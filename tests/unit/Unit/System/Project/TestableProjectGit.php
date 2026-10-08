<?php

namespace Tests\Unit\System\Project;

use App\System\Project;
use App\System\Project\Git as ProjectGit;

/**
 * Project Git with injectable execute() for unit tests (no DinD / host sudo).
 *
 * @internal
 */
final class TestableProjectGit extends ProjectGit
{
    use FakeGitExecution;

    /**
     * @param (callable(list<string>, ?string, int): string)|FakeGitRunner $execute
     */
    public function __construct(
        Project $project,
        ?string $path,
        $execute,
        ?string $absolutePathOverride = null,
    ) {
        parent::__construct($project, $path ?? 'public_html');
        $this->execute = $execute;
        if ($absolutePathOverride !== null) {
            $this->absolutePath = $absolutePathOverride;
            $this->pathKey = '';
        }
    }
}
