<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\JavaRuntime;
use PHPUnit\Framework\TestCase;

/**
 * A build tool the catalogue lists no toolchain for, and the toolchains offered.
 */
class JavaRuntimeToolchainTest extends TestCase
{
    public function test_a_tool_the_catalogue_has_no_toolchain_for_gets_maven(): void
    {
        $this->assertSame(JavaRuntime::MAVEN, JavaRuntime::toolchain('ant', 17));
    }

    public function test_a_gradle_toolchain_the_catalogue_does_not_list_gets_the_gradle_image(): void
    {
        $this->assertNotContains('11-gradle', JavaRuntime::toolchains());

        $this->assertSame(JavaRuntime::GRADLE_IMAGE, JavaRuntime::imageTag('11-gradle'));
    }

    public function test_the_supported_versions_are_the_toolchains(): void
    {
        $versions = (new JavaRuntime)->supportedVersions();

        $this->assertContains(JavaRuntime::defaultToolchain(), $versions);
        foreach ($versions as $version) {
            $this->assertMatchesRegularExpression('/^\d+-(maven|gradle)$/', $version);
        }
    }
}
