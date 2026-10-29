<?php

namespace Tests\Unit\Apis;

use App\Models\User;
use App\System;
use App\System\Project;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UserResolvePathTest extends TestCase
{
    public function test_resolve_builds_absolute_path_under_home(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->assertSame('/home/alice/project', $project->resolvePath('project'));
        $this->assertSame('/home/alice/public_html', $project->resolvePath('public_html'));
        $this->assertSame('/home/alice/public_html/', $project->resolvePath('public_html/'));
    }

    public function test_resolve_absolute_path_under_home(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->assertSame(
            '/home/alice/public_html',
            $project->resolvePath('/home/alice/public_html'),
        );
    }

    public function test_resolve_replaces_legacy_var_www_prefix(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->assertSame(
            '/home/alice/project',
            $project->resolvePath('/var/www/project'),
        );
    }

    public function test_resolve_keeps_a_sibling_home_with_the_same_prefix_as_a_whole_segment(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->assertSame('/home/alice/home/alice2/x', $project->resolvePath('/home/alice2/x'));
        $this->assertSame('/home/alice/home/alicex', $project->resolvePath('/home/alicex'));
    }

    public function test_resolve_reads_the_home_itself_as_the_home(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->assertSame('/home/alice/', $project->resolvePath('/home/alice'));
    }

    public function test_resolve_replaces_legacy_var_www_only_as_a_whole_segment(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->assertSame('/home/alice/', $project->resolvePath('/var/www'));
        $this->assertSame('/home/alice/var/www2/x', $project->resolvePath('/var/www2/x'));
    }

    public function test_resolve_rejects_path_traversal(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->expectException(ValidationException::class);
        $project->resolvePath('../etc/passwd');
    }

    public function test_resolve_rejects_control_characters(): void
    {
        $project = $this->projectWithHome('/home/alice');

        $this->expectException(ValidationException::class);
        $project->resolvePath("foo\0bar");
    }

    private function projectWithHome(string $home): Project
    {
        $system = $this->createStub(System::class);
        $system->method('projectHomeDirPath')->willReturn($home);
        $system->method('projectDirPath')->willReturn($home);

        $model = new User;
        $model->username = 'alice';
        $model->details = ['template' => 'dind'];

        return new Project($system, $model);
    }
}
