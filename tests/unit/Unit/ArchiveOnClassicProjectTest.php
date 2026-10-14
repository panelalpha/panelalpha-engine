<?php

namespace Tests\Unit;

use App\Lib\Project\ProjectRebuild;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * An archive deploy into a classic project is refused in the same words as
 * every other container-only operation.
 */
class ArchiveOnClassicProjectTest extends TestCase
{
    private function user(string $template): User
    {
        $user = new User();
        $user->username = 'arch' . bin2hex(random_bytes(3));
        $user->setDetails(['template' => $template]);

        return $user;
    }

    public function test_a_classic_project_is_refused_as_not_a_container_project(): void
    {
        try {
            ProjectRebuild::refuseArchiveOnClassicProject($this->user('php'));
            $this->fail('an archive into a classic project must be refused');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['zip_path' => ['Archive deploy works only on container projects (template dind).']],
                $e->errors(),
            );
        }
    }

    public function test_a_container_project_takes_archives(): void
    {
        ProjectRebuild::refuseArchiveOnClassicProject($this->user('dind'));
        $this->addToAssertionCount(1);
    }
}
