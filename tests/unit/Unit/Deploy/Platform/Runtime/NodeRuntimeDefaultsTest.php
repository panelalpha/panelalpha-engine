<?php

namespace Tests\Unit\Deploy\Platform\Runtime;

use App\Lib\Deploy\Platform\Runtime\NodeRuntime;
use PHPUnit\Framework\TestCase;

/**
 * The Node a project gets when it names none the resolver can read, and the versions offered.
 */
class NodeRuntimeDefaultsTest extends TestCase
{
    public function test_without_a_project_the_default_image_is_used(): void
    {
        $this->assertSame(NodeRuntime::defaultImage(), NodeRuntime::imageFor(''));
        $this->assertSame(NodeRuntime::imageTag(NodeRuntime::defaultMajor()), NodeRuntime::defaultImage());
    }

    public function test_an_engines_constraint_without_a_number_takes_the_default_major(): void
    {
        $image = NodeRuntime::imageFor('/nonexistent/pa-node-defaults', ['name' => 'app', 'engines' => ['node' => 'current']]);

        $this->assertStringStartsWith('node:'.NodeRuntime::defaultMajor(), $image);
    }

    public function test_the_supported_versions_are_the_majors(): void
    {
        $this->assertSame(NodeRuntime::majors(), (new NodeRuntime)->supportedVersions());
        $this->assertContains(NodeRuntime::defaultMajor(), NodeRuntime::majors());
    }
}
