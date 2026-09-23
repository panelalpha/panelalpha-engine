<?php

namespace Tests\Unit;

use App\Exceptions\ProblemException;
use App\Http\Controllers\UserController;
use App\Models\User;
use ReflectionMethod;
use Tests\TestCase;

/**
 * engine#269: an archive deployed into a project that deploys from git failed
 * with "Git HEAD is not readable after clone" -- after it had already replaced
 * ~/project. It is refused up front now, with what to do instead.
 */
class ArchiveOnGitProjectTest extends TestCase
{
    private function refuse(User $user): void
    {
        (new ReflectionMethod(UserController::class, 'refuseArchiveOnGitProject'))->invoke(null, $user);
    }

    private function user(?string $repo): User
    {
        $user = new User();
        $user->username = 'arch' . bin2hex(random_bytes(3));
        $user->setDetails(['template' => 'dind', 'git_repo' => $repo]);

        return $user;
    }

    public function test_a_git_project_is_refused_with_a_code_and_the_way_out(): void
    {
        try {
            $this->refuse($this->user('https://x-access-token:secret@github.com/dokuwiki/dokuwiki'));
            $this->fail('an archive into a git project must be refused');
        } catch (ProblemException $e) {
            $problem = $e->problems[0];
            $this->assertSame('zip_path', $problem['field']);
            $this->assertSame('archive_on_git_project', $problem['code']);
            $this->assertStringContainsString('created without a repository', $problem['message']);
            $this->assertStringNotContainsString('secret', $problem['git_repo']);
        }
    }

    public function test_a_project_without_a_repository_takes_archives(): void
    {
        $this->refuse($this->user(null));
        $this->refuse($this->user(''));
        $this->addToAssertionCount(1);
    }

    public function test_both_archive_entry_points_ask_before_touching_anything(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../app/Http/Controllers/UserController.php');

        foreach (['public function deployArchive(', 'public function rebuild(string $username'] as $entry) {
            $body = substr($source, (int) strpos($source, $entry));
            $refuse = strpos($body, 'self::refuseArchiveOnGitProject($user)');
            $work = strpos($body, 'DeployPlanInput::arm($request)');

            $this->assertNotFalse($refuse, "{$entry} does not refuse an archive into a git project");
            $this->assertLessThan($work, $refuse, "{$entry} refuses only after it has started work");
        }
    }
}
