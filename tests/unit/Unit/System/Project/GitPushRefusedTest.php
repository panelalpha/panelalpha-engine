<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Git\CouldNotRun;
use App\System\Project\Git\Exception as GitException;
use App\System\Project\Git\PushRefused;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A push the repository turns down is a 422 with a problem code, in words rather than git's: rejected naming the ref
 * git refused, or not authorized. Any other failure is left as git reported it.
 */
class GitPushRefusedTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function unauthorizedPushes(): iterable
    {
        yield 'no token stored' => ["error: unable to read askpass response from '/bin/false'\n"
            ."fatal: could not read Username for 'https://github.com': No such device or address\n"];
        yield 'prompts disabled' => ["fatal: could not read Username for 'https://github.com': terminal prompts disabled\n"];
        yield 'GitHub token that may only read' => ["remote: Permission to octocat/Hello-World.git denied to alice.\n"
            ."fatal: unable to access 'https://github.com/octocat/Hello-World.git/': The requested URL returned error: 403\n"];
        yield 'GitHub fine-grained token without write' => ["remote: Write access to repository not granted.\n"
            ."fatal: unable to access 'https://github.com/o/r.git/': The requested URL returned error: 403\n"];
        yield 'GitHub token refused' => ["remote: Invalid username or token. Password authentication is not supported for Git operations.\n"
            ."fatal: Authentication failed for 'https://github.com/o/r.git/'\n"];
        yield 'GitLab member who may not push' => ["remote: You are not allowed to push code to this project.\n"
            ."fatal: unable to access 'https://gitlab.com/o/r.git/': The requested URL returned error: 403\n"];
        yield 'GitLab token refused' => ["remote: HTTP Basic: Access denied.\nfatal: Authentication failed for 'https://gitlab.com/o/r.git/'\n"];
        yield 'GitHub read-only deploy key' => ["ERROR: The key you are authenticating with has been marked as read only.\n"
            ."fatal: Could not read from remote repository.\n"];
        yield 'GitLab read-only deploy key' => ["remote: ERROR: This deploy key does not have write access to this project.\n"
            ."fatal: Could not read from remote repository.\n"];
    }

    #[DataProvider('unauthorizedPushes')]
    public function test_a_push_without_write_access_is_not_authorized(string $output): void
    {
        $refusal = PushRefused::of(new GitException($output, 400));

        $this->assertInstanceOf(PushRefused::class, $refusal);
        $this->assertSame(422, $refusal->httpStatus);
        $this->assertSame(PushRefused::UNAUTHORIZED, $refusal->problemCode);
        $this->assertStringStartsWith('The repository did not let this project push to it', $refusal->getMessage());
        $this->assertStringNotContainsString('askpass', $refusal->getMessage());
    }

    /** @return iterable<string, array{string, string}> */
    public static function rejectedPushes(): iterable
    {
        yield 'the branch moved on' => [
            "To https://github.com/o/r.git\n ! [rejected]        HEAD -> main (fetch first)\n"
            ."error: failed to push some refs to 'https://github.com/o/r.git'\n",
            '! [rejected] HEAD -> main (fetch first)',
        ];
        yield 'a protected branch' => [
            "remote: error: GH006: Protected branch update failed for refs/heads/main.\nTo https://github.com/o/r.git\n"
            ." ! [remote rejected] HEAD -> main (protected branch hook declined)\n"
            ."error: failed to push some refs to 'https://github.com/o/r.git'\n",
            '! [remote rejected] HEAD -> main (protected branch hook declined)',
        ];
        yield 'a GitLab protected branch' => [
            "remote: GitLab: You are not allowed to push code to protected branches on this project.\n"
            ."To https://gitlab.com/o/r.git\n ! [remote rejected] HEAD -> main (pre-receive hook declined)\n"
            ."error: failed to push some refs to 'https://gitlab.com/o/r.git'\n",
            '! [remote rejected] HEAD -> main (pre-receive hook declined)',
        ];
        yield 'a Gitea protected branch' => [
            "remote: \nremote: Gitea: Not allowed to push to protected branch main\n"
            ."To https://gitea.example.test/o/r.git\n ! [remote rejected] HEAD -> main (pre-receive hook declined)\n"
            ."error: failed to push some refs to 'https://gitea.example.test/o/r.git'\n",
            '! [remote rejected] HEAD -> main (pre-receive hook declined)',
        ];
    }

    #[DataProvider('rejectedPushes')]
    public function test_a_push_the_repository_declines_is_rejected_naming_the_ref(string $output, string $line): void
    {
        $refusal = PushRefused::of(new GitException($output, 400));

        $this->assertSame(422, $refusal->httpStatus);
        $this->assertSame(PushRefused::REJECTED, $refusal->problemCode);
        $this->assertStringStartsWith("The repository rejected the push ({$line}): ", $refusal->getMessage());
    }

    public function test_a_remote_that_cannot_be_reached_stays_as_git_said_it(): void
    {
        $failure = new GitException("fatal: unable to access 'https://github.com/o/r.git/': Could not resolve host: github.com\n", 400);

        $this->assertSame($failure, PushRefused::of($failure));
    }

    public function test_a_failure_already_told_apart_keeps_its_own_answer(): void
    {
        $keyRefused = new GitException('Permission denied (publickey). The repository does not accept this project\'s deploy key.', 422, 'git_ssh_key_refused');
        $notRun = CouldNotRun::inContainer('service "dind" is not running', true);

        $this->assertSame($keyRefused, PushRefused::of($keyRefused));
        $this->assertSame($notRun, PushRefused::of($notRun));
    }
}
