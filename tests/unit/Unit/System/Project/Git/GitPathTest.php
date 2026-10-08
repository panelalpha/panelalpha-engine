<?php

namespace Tests\Unit\System\Project\Git;

use App\System\Project\Git\Path as GitPath;
use PHPUnit\Framework\TestCase;

class GitPathTest extends TestCase
{
    public function test_relative_and_absolute_under_home_are_the_same_key(): void
    {
        $home = '/home/alice';
        $this->assertSame('public_html', GitPath::key('public_html', $home));
        $this->assertSame('public_html', GitPath::key('/public_html', $home));
        $this->assertSame('public_html', GitPath::key('public_html/', $home));
        $this->assertSame('public_html', GitPath::key('/home/alice/public_html', $home));
        $this->assertSame('public_html', GitPath::key('/home/alice/public_html/', $home));
    }

    public function test_home_itself_is_empty_key(): void
    {
        $this->assertSame('', GitPath::key('/home/alice', '/home/alice'));
        $this->assertSame('', GitPath::key('', '/home/alice'));
    }

    public function test_project_key_under_home(): void
    {
        $this->assertSame('project', GitPath::key('/home/alice/project', '/home/alice'));
        $this->assertSame('project', GitPath::key('project', '/home/alice'));
    }
}
