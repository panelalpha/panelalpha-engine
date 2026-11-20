<?php

namespace Tests\Unit\Deploy\Compose;

use App\Lib\Deploy\Compose\WritableProjectBinds;
use PHPUnit\Framework\TestCase;

/** Volume shapes that hand nothing writable over, and files with nothing to read. */
class WritableProjectBindsShapesTest extends TestCase
{
    private const PROJECT = '/home/acct/project';

    public function test_a_file_without_services_or_a_project_dir_writes_nothing(): void
    {
        $this->assertSame([], WritableProjectBinds::of(['version' => '3'], self::PROJECT));
        $this->assertSame([], WritableProjectBinds::of(['services' => ['app' => ['volumes' => ['./data:/data']]]], '/'));
    }

    public function test_only_mappable_read_write_binds_inside_the_checkout_count(): void
    {
        $compose = ['services' => [
            'anchor' => 'x-not-a-service',
            'novolumes' => ['image' => 'alpine', 'volumes' => 'data:/data'],
            'odd' => ['image' => 'alpine', 'volumes' => ['/data', 42, '/../etc:/etc', './../../etc:/etc']],
            'app' => ['image' => 'acme/app', 'user' => '1000', 'volumes' => ['/data', './uploads:/app/uploads', 7]],
        ]];

        $this->assertSame([
            'app' => ['image' => 'acme/app', 'user' => '1000', 'sources' => [self::PROJECT . '/uploads']],
        ], WritableProjectBinds::of($compose, self::PROJECT));
    }
}
