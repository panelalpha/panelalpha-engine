<?php

namespace Tests\Unit\Backup;

use Tests\TestCase;

class ProjectBackupCreateCommandTest extends TestCase
{
    public function test_create_command_requires_container_option(): void
    {
        $exitCode = $this->artisan('project:backup:create', [
            'project' => 'alice',
        ]);

        $exitCode->assertExitCode(1);
    }
}
