<?php

namespace Tests\Unit\Copy;

use App\Models\User;
use App\System;
use App\System\Project;
use Tests\TestCase;

/**
 * Non-DinD (PHP hosting) projects have no volume copy surface — the aggregate
 * returns zeros / null rather than throwing.
 */
class AbstractProjectCopyPrimitivesTest extends TestCase
{
    public function test_php_hosting_volume_primitives_are_noops(): void
    {
        $system = $this->createStub(System::class);
        $system->method('projectHomeDirPath')->willReturn(sys_get_temp_dir() . '/pa-pc-missing');
        $system->method('projectDirPath')->willReturn(sys_get_temp_dir() . '/pa-pc-missing');

        $model = new User();
        $model->username = 'wp';
        $model->details = ['template' => 'default'];

        $project = new Project($system, $model);
        $sourceModel = new User();
        $sourceModel->username = 'src';
        $sourceModel->details = ['template' => 'default'];
        $source = new Project($system, $sourceModel);

        $this->assertNull($project->copyVolumesForClone());
        $this->assertSame(0, $project->copyVolumeDataFrom($source));
        $this->assertSame(0, $project->copyVolumeDataToIncoming($source));
        $this->assertNotSame('dind', $project->kind());
    }
}
