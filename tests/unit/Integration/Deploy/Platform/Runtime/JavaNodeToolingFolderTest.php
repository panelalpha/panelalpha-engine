<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JavaNodeTooling;
use PHPUnit\Framework\TestCase;

/**
 * A folder named like a build script is not read as one.
 */
class JavaNodeToolingFolderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-java-node-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/plugins.gradle', 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_a_folder_named_like_a_gradle_script_needs_no_node(): void
    {
        file_put_contents($this->dir.'/build.gradle', "plugins { id 'java' }\n");

        $this->assertNull(JavaNodeTooling::nodeImageFor($this->dir));
    }

    public function test_a_script_beside_the_folder_still_names_node(): void
    {
        file_put_contents($this->dir.'/build.gradle', "plugins { id 'com.github.node-gradle.node' version '7.0.0' }\n");

        $this->assertNotNull(JavaNodeTooling::nodeImageFor($this->dir));
    }
}
