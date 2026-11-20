<?php

namespace Tests\Unit\Deploy;

use App\Lib\Deploy\ProjectCache;
use App\Lib\Deploy\ProjectSetup;
use PHPUnit\Framework\TestCase;

/**
 * A composer `setup` script of an unusable shape, and a cache name nobody declared.
 */
class ProjectSetupDeclaredShapesTest extends TestCase
{
    public function test_a_composer_setup_list_runs_it_and_an_unusable_one_does_not(): void
    {
        $this->assertSame('composer run-script --no-interaction setup', ProjectSetup::command('{"scripts":{"setup":["@php artisan key:generate"]}}'));
        $this->assertNull(ProjectSetup::command('{"scripts":{"setup":[]}}'));
        $this->assertNull(ProjectSetup::command('{"scripts":{"setup":42}}'));
        $this->assertNull(ProjectSetup::command('{"scripts":{"setup":"  "}}'));
    }

    public function test_a_cache_name_outside_the_list_is_refused_before_it_reaches_a_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown project cache: ../etc');

        ProjectCache::subdirFor('alice', '../etc');
    }
}
