<?php

namespace Tests\Integration\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A Gradle wrapper whose properties name no distribution has no version to read.
 */
class JavaGradleWrapperVersionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pa-gradle-wrapper-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/gradle/wrapper', 0o755, true);
        file_put_contents($this->dir.'/gradlew', "#!/bin/sh\n");
        file_put_contents($this->dir.'/gradle/wrapper/gradle-wrapper.jar', 'jar');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->dir));
        parent::tearDown();
    }

    public function test_properties_without_a_distribution_url_have_no_version(): void
    {
        file_put_contents($this->dir.'/gradle/wrapper/gradle-wrapper.properties', "zipStoreBase=GRADLE_USER_HOME\n");

        $this->assertTrue(JavaRuntime::hasGradleWrapper($this->dir));
        $this->assertNull(JavaRuntime::gradleWrapperVersion($this->dir));
    }

    public function test_the_version_is_read_from_the_distribution_url(): void
    {
        file_put_contents($this->dir.'/gradle/wrapper/gradle-wrapper.properties', "distributionUrl=https\\://services.gradle.org/distributions/gradle-8.5-bin.zip\n");

        $this->assertSame('8.5', JavaRuntime::gradleWrapperVersion($this->dir));
    }
}
