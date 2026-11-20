<?php

namespace Tests\Integration\Deploy\DeployLog;

use App\Lib\Deploy\DeployLog\DeployLogArchive;
use App\Lib\Deploy\DeployLog\DeployLogPaths;
use Tests\TestCase;

/** A host that never deployed has no log directory, and so no accounts with logs. */
class DeployLogArchiveNoBaseTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = storage_path();
        $this->app->useStoragePath(sys_get_temp_dir() . '/pa-no-deploy-logs-' . bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->app->useStoragePath($this->storage);
        parent::tearDown();
    }

    public function test_no_log_directory_lists_no_accounts(): void
    {
        $this->assertDirectoryDoesNotExist(DeployLogPaths::base());
        $this->assertSame([], DeployLogArchive::users());
        $this->assertSame([], DeployLogArchive::pruneAll(1, true));
    }
}
