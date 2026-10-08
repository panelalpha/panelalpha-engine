<?php

namespace Tests\Unit\Deploy\Source;

use App\Lib\Deploy\Source\ProjectArchive;
use PHPUnit\Framework\TestCase;

class ProjectArchiveTest extends TestCase
{
    private const DIR = '/home/acct/.panelalpha/archive-extract-0123';

    public function test_lists_the_top_level_as_root(): void
    {
        $this->assertSame(
            ['sudo', 'find', self::DIR, '-mindepth', '1', '-maxdepth', '1', '-printf', '%y %f\0'],
            ProjectArchive::topLevelArgv(self::DIR)
        );
    }

    public function test_unwraps_single_subdirectory(): void
    {
        $this->assertSame(self::DIR . '/my-app', ProjectArchive::resolveProjectRoot(self::DIR, "d my-app\0"));
    }

    public function test_keeps_flat_root_when_files_present(): void
    {
        $this->assertSame(self::DIR, ProjectArchive::resolveProjectRoot(self::DIR, "f index.html\0d assets\0"));
        $this->assertSame(self::DIR, ProjectArchive::resolveProjectRoot(self::DIR, "d assets\0f index.html\0"));
    }

    public function test_keeps_root_when_multiple_directories(): void
    {
        $this->assertNull(ProjectArchive::findSingleDir(self::DIR, "d a\0d b\0"));
        $this->assertSame(self::DIR, ProjectArchive::resolveProjectRoot(self::DIR, "d a\0d b\0"));
    }

    public function test_keeps_root_when_empty(): void
    {
        $this->assertSame(self::DIR, ProjectArchive::resolveProjectRoot(self::DIR, ''));
    }

    public function test_ignores_ds_store_when_finding_single_dir(): void
    {
        $this->assertSame(self::DIR . '/app', ProjectArchive::findSingleDir(self::DIR, "f .DS_Store\0d app\0"));
    }

    public function test_a_link_to_a_directory_is_not_unwrapped(): void
    {
        $this->assertNull(ProjectArchive::findSingleDir(self::DIR, "l app\0"));
    }

    public function test_keeps_a_name_with_spaces_whole(): void
    {
        $this->assertSame(self::DIR . '/my app ', ProjectArchive::findSingleDir(self::DIR, "d my app \0"));
    }
}
