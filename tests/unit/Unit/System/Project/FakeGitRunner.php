<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Git\Exception as GitException;

final class FakeGitRunner
{
    /** @var list<list<string>> */
    public array $commands = [];

    /** @var array<string, string> */
    public array $stdout = [];

    /** @var list<string> */
    public array $failIfContains = [];

    /**
     * @param list<string> $gitArgs
     */
    public function __invoke(array $gitArgs, ?string $token, int $timeout = 600): string
    {
        return $this->run($gitArgs, $token, $timeout);
    }

    /**
     * @param list<string> $gitArgs
     */
    public function run(array $gitArgs, ?string $token, int $timeout = 600): string
    {
        $this->commands[] = $gitArgs;
        $joined = implode(' ', $gitArgs);
        foreach ($this->failIfContains as $needle) {
            if (str_contains($joined, $needle)) {
                throw new GitException('git failed: ' . $joined, 400);
            }
        }
        foreach ($this->stdout as $needle => $out) {
            if (str_contains($joined, $needle)) {
                return $out;
            }
        }

        return '';
    }
}
