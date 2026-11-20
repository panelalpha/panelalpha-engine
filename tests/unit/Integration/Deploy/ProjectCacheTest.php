<?php

namespace Tests\Integration\Deploy;

use App\Lib\Deploy\ProjectCache;
use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

class ProjectCacheTest extends TestCase
{
    public function test_prepare_never_names_another_projects_directory(): void
    {
        $script = LocalRoot::script(Privileged::host()->prepareProjectCache('alice', '1004:1004'));

        $this->assertStringNotContainsString(ProjectCache::dirFor('bob'), $script);
        // The shared root as an argument of its own would chown every project.
        $this->assertStringNotContainsString("'" . ProjectCache::ROOT . "'", $script);
    }
}
