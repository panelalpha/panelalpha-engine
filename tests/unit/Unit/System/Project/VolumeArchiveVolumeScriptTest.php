<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;

class VolumeArchiveVolumeScriptTest extends TestCase
{
    public function test_a_volume_name_with_a_trailing_newline_is_refused(): void
    {
        $check = new \ReflectionMethod(VolumeArchive::class, 'assertDockerVolumeName');
        $archive = (new \ReflectionClass(VolumeArchive::class))->newInstanceWithoutConstructor();
        $check->invoke($archive, 'project_data');

        $this->expectExceptionMessage('Invalid docker volume name');
        $check->invoke($archive, "project_data\n");
    }
}
